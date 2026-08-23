<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\Rotation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * LES CINQ DIMENSIONS DE ROTATION — constat B12-007 (S1).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT :
 *
 *     public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
 *
 * Un corps ECRIT DANS LE CODE, rendu avec un `200`. `RotationsPage.tsx` calcule
 * `total = data.data.length` et affiche son `EmptyState` des que `total === 0` :
 * l'ecran « Rotations » annoncait donc « aucune rotation configuree » quoi que
 * contienne la table.
 *
 * C'est une affirmation trompeuse a deux etages : non seulement l'exploitant ne
 * voit pas ses rotations, mais il croit legitimement qu'il n'en a aucune — et
 * la rotation est precisement le mecanisme qui evite de se faire bannir en
 * collecte. Croire qu'on n'en a pas quand on en a est aussi couteux que
 * l'inverse.
 *
 * ✅ La table `rotations` EXISTE (migration `2026_05_16_000004`) : cinq
 * dimensions sous contrainte CHECK (`proxy`, `user_agent`, `target`,
 * `search_engine`, `llm`), poids, temporisation, `last_used_at`. Elle porte
 * meme son trigger `rotations_updated_at`. La piece etait posee ; la route ne
 * la regardait pas.
 *
 * Garde : `tests/Feature/Api/CorpsCodeEnDurTest.php`.
 */
class RotationsController extends ApiController
{
    use VerrouOptimiste;

    /**
     * Cinq dimensions, quelques dizaines de lignes par espace au plus : le
     * plafond n'est pas ici une contrainte de volume, c'est le refus de principe
     * d'une lecture non bornee (`G41-007`).
     */
    private const PLAFOND = 200;

    /**
     * @OA\Get(path="/rotations", tags={"Rotations"}, summary="Liste les rotations LLM (round-robin / cost-based)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="dimension", in="query", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('rotations')) {
            return $this->ok(['data' => []]);
        }

        // Fail-closed : sans contexte d'espace, on ne rend pas la configuration
        // de collecte de tous les clients (`P6-API-001`).
        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            return $this->ok(['data' => []]);
        }

        try {
            $requete = DB::table('rotations')
                ->where('workspace_id', $espace);

            // Le filtre n'est accepte que s'il correspond a une valeur admise
            // par la contrainte CHECK de la table. Une dimension inventee ne
            // remonte pas jusqu'a Postgres : elle est simplement ignoree, et la
            // liste complete est rendue — un filtre inconnu ne doit pas rendre
            // une liste vide qui se lirait « aucune rotation ».
            $dimension = (string) $r->query('dimension', '');
            if (in_array($dimension, ['proxy', 'user_agent', 'target', 'search_engine', 'llm'], true)) {
                $requete->where('dimension', $dimension);
            }

            $lignes = $requete
                ->orderBy('dimension')
                ->orderBy('slug')
                ->limit(self::PLAFOND)
                ->get([
                    'id', 'dimension', 'slug', 'weight',
                    'cooldown_seconds', 'enabled', 'last_used_at',
                ])
                ->map(fn (object $l) => (array) $l)
                ->all();

            return $this->ok(['data' => $lignes]);
        } catch (\Throwable $e) {
            Log::error('rotations.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * ⚠️ CETTE METHODE NE RECOIT PAS DE MODELE, ET C'EST CE QUI LA REND
     * DIFFERENTE DE SES VOISINES.
     *
     * `LlmUseCasesController` et `ProxyProvidersController` recoivent un modele
     * par resolution de route et posent `refuserHorsEspace()`. Ici la signature
     * est `int $rotation` : il n'y a aucun modele a refuser, donc le
     * cloisonnement doit etre ecrit A LA MAIN dans la requete — et s'il
     * manquait, RIEN ne rougirait, puisque le controle de completude du
     * correctif de cloisonnement n'enumere que les methodes qui recoivent un
     * modele.
     *
     * C'est exactement l'angle mort qui avait laisse passer `P6-API-001` sur
     * les listes. On l'ecrit donc explicitement, et la garde le mesure sur le
     * CONTENU de la base, pas sur la presence d'un appel de methode.
     *
     * `dimension` et `slug` ne sont pas modifiables : ensemble ils IDENTIFIENT
     * la rotation pour le tirage. Ce qui se regle ici, c'est son comportement.
     *
     * @OA\Put(path="/rotations/{rotation}", tags={"Rotations"}, summary="Règle une rotation de l'espace courant",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="rotation", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnue, ou hors de mon espace"),
     *     @OA\Response(response=422, description="Champs invalides"))
     */
    public function update(Request $r, int $rotation): JsonResponse
    {
        $espace = $this->espaceCourantOuNull();
        if ($espace === null) {
            abort(404);
        }

        $courant = Rotation::query()
            ->whereKey($rotation)
            ->where('workspace_id', $espace)
            ->first();

        // 404 et non 403 : « interdit » confirmerait l'existence de la ligne a
        // qui n'a pas le droit de la voir.
        if ($courant === null) {
            abort(404);
        }

        // 🔑 G43-005 — VERROU OPTIMISTE. La garde `VerrouOptimisteEtenduTest`
        // avait ANTICIPE ce moment : « un update() qui rend 501 n'ecrit rien :
        // il n'y a pas de saisie a perdre. Le jour ou il est cable, il
        // apparaitra ici. » Il l'a fait, le 2026-08-23, et sa liste de
        // derogations est vide A DESSEIN — « ce n'est pas une derogation, c'est
        // le registre de ce qui reste a faire ».
        //
        // Sans ce controle, deux saisies concurrentes perdent du travail EN
        // SILENCE : la seconde ecrase la premiere sans que personne l'apprenne.
        $this->refuserSiVersionPerimee($r, $courant);

        $valide = $r->validate([
            // Un poids negatif n'a aucun sens dans un tirage pondere ; un poids
            // demesure revient a desactiver silencieusement tous les autres.
            'weight' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            // Un delai de repos negatif placerait la prochaine eligibilite dans
            // le passe : la rotation ne se reposerait jamais.
            'cooldown_seconds' => ['sometimes', 'integer', 'min:0', 'max:86400'],
            'enabled' => ['sometimes', 'boolean'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $champs = [];
        foreach (['weight', 'cooldown_seconds', 'enabled'] as $clef) {
            if (array_key_exists($clef, $valide)) {
                $champs[$clef] = $valide[$clef];
            }
        }
        if (array_key_exists('metadata', $valide)) {
            $champs['metadata'] = json_encode($valide['metadata'], JSON_UNESCAPED_UNICODE);
        }

        if ($champs !== []) {
            $champs['updated_at'] = now();
            DB::table('rotations')
                ->where('id', $rotation)
                ->where('workspace_id', $espace)
                ->update($champs);
        }

        $apres = DB::table('rotations')
            ->where('id', $rotation)
            ->where('workspace_id', $espace)
            ->first(['id', 'dimension', 'slug', 'weight', 'cooldown_seconds', 'enabled', 'last_used_at']);

        return $this->ok(['data' => $apres === null ? null : (array) $apres]);
    }
}
