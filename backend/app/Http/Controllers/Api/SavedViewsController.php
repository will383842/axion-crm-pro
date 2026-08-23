<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Concerns\VerrouOptimiste;
use App\Models\SavedView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * LES VUES SAUVEGARDEES — constats A-002 (deja ouvert) et B12-007 (S1).
 *
 * 🔴 CE QU'IL Y AVAIT AVANT :
 *
 *     public function index(Request $r): JsonResponse { return $this->ok(['data' => []]); }
 *
 * `A-002` a ete ouvert sur CETTE route, et sur elle seule. `B12-007` a montre
 * qu'il y en avait dix du meme motif : le constat n'etait pas « une route
 * repond 200 vide », c'etait « le depot ecrit des corps en dur ».
 *
 * ✅ La table `saved_views` EXISTE (migration `2026_05_16_000006`) :
 * `workspace_id`, `user_id`, `entity`, `name`, `filters JSONB`, `is_default`,
 * sous `UNIQUE (user_id, entity, name)`. C'est cette contrainte qui tranche la
 * question du cloisonnement : une vue appartient a une PERSONNE, pas a l'espace.
 * On filtre donc sur les deux, comme la cloche de notifications.
 *
 * ⚠️ CE QUE CE CORRECTIF NE FAIT PAS. `store`, `show`, `update`, `destroy`
 * restent en `501` (Sprint 10). La liste sera donc vide en pratique tant que
 * rien ne peut y ecrire — mais elle sera vide PARCE QU'ELLE A REGARDE, et le
 * jour ou l'ecriture arrivera, la lecture n'aura pas a etre redecouverte.
 *
 * *La difference entre « vide » et « pas regarde » est toute la difference
 * entre un ecran et un faux temoin.*
 *
 * Garde : `tests/Feature/Api/CorpsCodeEnDurTest.php`.
 */
class SavedViewsController extends ApiController
{
    use VerrouOptimiste;

    /** Des filtres sauvegardes : quelques dizaines par personne, jamais mille. */
    private const PLAFOND = 100;

    /**
     * @OA\Get(path="/saved-views", tags={"SavedViews"}, summary="Liste vues sauvegardées (filtres companies/contacts)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="entity", in="query", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('saved_views')) {
            return $this->ok(['data' => []]);
        }

        $espace = $this->espaceCourantOuNull();
        $utilisateur = $r->user();

        // Fail-closed sur les deux dimensions : un filtre sauvegarde nomme les
        // criteres de prospection de son auteur, ce n'est pas une donnee
        // d'equipe par defaut.
        if ($espace === null || $utilisateur === null) {
            return $this->ok(['data' => []]);
        }

        try {
            $requete = DB::table('saved_views')
                ->where('workspace_id', $espace)
                ->where('user_id', $utilisateur->getKey());

            // `entity` est une chaine libre en base ; on l'accepte telle quelle
            // mais bornee, pour qu'une valeur absurde ne parte pas en requete.
            $entite = trim((string) $r->query('entity', ''));
            if ($entite !== '' && mb_strlen($entite) <= 64) {
                $requete->where('entity', $entite);
            }

            $lignes = $requete
                ->orderByDesc('is_default')
                ->orderBy('name')
                ->limit(self::PLAFOND)
                ->get(['id', 'entity', 'name', 'filters', 'is_default', 'created_at', 'updated_at'])
                ->map(function (object $ligne): array {
                    $t = (array) $ligne;

                    // `filters` est un JSONB : le pilote le restitue en CHAINE.
                    // Le rendre tel quel obligerait l'ecran a le decoder une
                    // seconde fois — meme traitement que `impact_assessment`
                    // dans `AiActRegisterController`.
                    $t['filters'] = json_decode((string) ($t['filters'] ?? '{}'), true) ?: [];

                    return $t;
                })
                ->all();

            return $this->ok(['data' => $lignes]);
        } catch (\Throwable $e) {
            Log::error('saved-views.index failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['data' => [], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/saved-views", tags={"SavedViews"}, summary="Crée une vue sauvegardée",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Response(response=201, description="Créée"),
     *     @OA\Response(response=422, description="Champs invalides, ou nom déjà pris pour cette entité"))
     */
    public function store(Request $r): JsonResponse
    {
        [$espace, $utilisateur] = $this->qui($r);
        if ($espace === null || $utilisateur === null) {
            abort(404);
        }

        $valide = $r->validate([
            'entity' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:120'],
            'filters' => ['sometimes', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $this->refuserNomDejaPris($espace, $utilisateur, $valide['entity'], $valide['name'], null);

        $parDefaut = (bool) ($valide['is_default'] ?? false);

        $id = DB::transaction(function () use ($espace, $utilisateur, $valide, $parDefaut): int {
            if ($parDefaut) {
                $this->retirerLeDefautDesAutres($espace, $utilisateur, $valide['entity'], null);
            }

            return (int) DB::table('saved_views')->insertGetId([
                'workspace_id' => $espace,
                'user_id' => $utilisateur,
                'entity' => $valide['entity'],
                'name' => $valide['name'],
                'filters' => json_encode($valide['filters'] ?? [], JSON_UNESCAPED_UNICODE),
                'is_default' => $parDefaut,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return $this->ok(['data' => $this->mienneOu404($espace, $utilisateur, $id)], 201);
    }

    /**
     * @OA\Get(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Une vue sauvegardée",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnue, ou hors de mon espace / de mes vues"))
     */
    public function show(Request $r, int $savedView): JsonResponse
    {
        [$espace, $utilisateur] = $this->qui($r);
        if ($espace === null || $utilisateur === null) {
            abort(404);
        }

        return $this->ok(['data' => $this->mienneOu404($espace, $utilisateur, $savedView)]);
    }

    /**
     * @OA\Put(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Modifie une vue sauvegardée",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnue, ou hors de mon espace / de mes vues"),
     *     @OA\Response(response=422, description="Champs invalides, ou nom déjà pris"))
     */
    public function update(Request $r, int $savedView): JsonResponse
    {
        [$espace, $utilisateur] = $this->qui($r);
        if ($espace === null || $utilisateur === null) {
            abort(404);
        }

        // On lit AVANT de valider : une modification sur la vue d'un collegue
        // doit rendre 404, pas 422. Sinon le message de validation confirme
        // l'existence de la ligne a qui n'a pas le droit de la voir.
        $actuelle = $this->mienneOu404($espace, $utilisateur, $savedView);

        $modele = SavedView::query()->whereKey($savedView)->firstOrFail();

        // 🔑 G43-005 — VERROU OPTIMISTE. La garde `VerrouOptimisteEtenduTest`
        // avait ANTICIPE ce moment : « un update() qui rend 501 n'ecrit rien :
        // il n'y a pas de saisie a perdre. Le jour ou il est cable, il
        // apparaitra ici. » Il l'a fait, le 2026-08-23, et sa liste de
        // derogations est vide A DESSEIN — « ce n'est pas une derogation, c'est
        // le registre de ce qui reste a faire ».
        //
        // Sans ce controle, deux saisies concurrentes perdent du travail EN
        // SILENCE : la seconde ecrase la premiere sans que personne l'apprenne.
        $this->refuserSiVersionPerimee($r, $modele);

        $valide = $r->validate([
            'entity' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:120'],
            'filters' => ['sometimes', 'array'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        $entite = (string) ($valide['entity'] ?? $actuelle['entity']);
        $nom = (string) ($valide['name'] ?? $actuelle['name']);

        $this->refuserNomDejaPris($espace, $utilisateur, $entite, $nom, $savedView);

        $champs = ['entity' => $entite, 'name' => $nom, 'updated_at' => now()];
        if (array_key_exists('filters', $valide)) {
            $champs['filters'] = json_encode($valide['filters'], JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('is_default', $valide)) {
            $champs['is_default'] = (bool) $valide['is_default'];
        }

        DB::transaction(function () use ($espace, $utilisateur, $savedView, $entite, $champs, $valide): void {
            if (! empty($valide['is_default'])) {
                $this->retirerLeDefautDesAutres($espace, $utilisateur, $entite, $savedView);
            }

            DB::table('saved_views')
                ->where('id', $savedView)
                ->where('workspace_id', $espace)
                ->where('user_id', $utilisateur)
                ->update($champs);
        });

        return $this->ok(['data' => $this->mienneOu404($espace, $utilisateur, $savedView)]);
    }

    /**
     * @OA\Delete(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Supprime une vue sauvegardée",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"),
     *     @OA\Response(response=404, description="Inconnue, ou hors de mon espace / de mes vues"))
     */
    public function destroy(Request $r, int $savedView): JsonResponse
    {
        [$espace, $utilisateur] = $this->qui($r);
        if ($espace === null || $utilisateur === null) {
            abort(404);
        }

        // Le 404 vient de la MEME lecture cloisonnee que partout ailleurs : on
        // ne supprime jamais « au jugé » avec un `delete()->where(...)` dont on
        // lirait ensuite le nombre de lignes — ce nombre vaut 0 aussi bien pour
        // « pas a moi » que pour « deja supprimee », et les deux ne meritent
        // pas la meme reponse.
        $this->mienneOu404($espace, $utilisateur, $savedView);

        DB::table('saved_views')
            ->where('id', $savedView)
            ->where('workspace_id', $espace)
            ->where('user_id', $utilisateur)
            ->delete();

        return $this->ok(['deleted' => $savedView]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Les quatre ecritures partagent exactement le meme cloisonnement.
    // Il est ecrit UNE fois : trois controleurs de ce depot portaient chacun
    // leur copie de ce controle, et le correctif n'etait alle que dans un seul
    // (cf. `ApiController::espaceCourantOuNull`). On ne recommence pas.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * L'espace courant et le compte courant, ou `null` pour l'un et l'autre.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function qui(Request $r): array
    {
        $utilisateur = $r->user();

        return [
            $this->espaceCourantOuNull(),
            $utilisateur === null ? null : (string) $utilisateur->getKey(),
        ];
    }

    /**
     * La vue, si elle est a MOI dans MON espace. Sinon 404 — jamais 403.
     *
     * @return array<string, mixed>
     */
    private function mienneOu404(string $espace, string $utilisateur, int $id): array
    {
        $ligne = DB::table('saved_views')
            ->where('id', $id)
            ->where('workspace_id', $espace)
            ->where('user_id', $utilisateur)
            ->first(['id', 'entity', 'name', 'filters', 'is_default', 'created_at', 'updated_at']);

        if ($ligne === null) {
            abort(404);
        }

        $t = (array) $ligne;
        // Meme traitement que `index()` : `filters` est un JSONB que le pilote
        // restitue en CHAINE. Les deux cotes doivent s'accorder, sinon l'ecran
        // decode d'un cote et pas de l'autre.
        $t['filters'] = json_decode((string) ($t['filters'] ?? '{}'), true) ?: [];
        $t['is_default'] = (bool) $t['is_default'];

        return $t;
    }

    /**
     * `UNIQUE (user_id, entity, name)` est dans la migration.
     *
     * On le verifie AVANT d'ecrire pour rendre un 422 qui NOMME le champ, au
     * lieu de laisser Postgres lever une `QueryException` que le gestionnaire
     * transforme en 500. « Ce nom existe deja » est une reponse ; « erreur
     * serveur » est un aveu.
     */
    private function refuserNomDejaPris(
        string $espace,
        string $utilisateur,
        string $entite,
        string $nom,
        ?int $saufId,
    ): void {
        $requete = DB::table('saved_views')
            ->where('workspace_id', $espace)
            ->where('user_id', $utilisateur)
            ->where('entity', $entite)
            ->where('name', $nom);

        if ($saufId !== null) {
            $requete->where('id', '!=', $saufId);
        }

        if ($requete->exists()) {
            throw ValidationException::withMessages([
                'name' => "Une vue « {$nom} » existe déjà pour « {$entite} ».",
            ]);
        }
    }

    /**
     * « Par defaut » au pluriel ne veut rien dire.
     *
     * La migration ne pose AUCUNE contrainte la-dessus : rien n'empeche
     * physiquement deux vues par defaut pour la meme entite. C'est donc au code
     * de tenir l'invariant, et de le tenir dans la MEME transaction que
     * l'ecriture — sans quoi une coupure entre les deux laisserait soit zero
     * defaut, soit deux.
     */
    private function retirerLeDefautDesAutres(
        string $espace,
        string $utilisateur,
        string $entite,
        ?int $saufId,
    ): void {
        $requete = DB::table('saved_views')
            ->where('workspace_id', $espace)
            ->where('user_id', $utilisateur)
            ->where('entity', $entite)
            ->where('is_default', true);

        if ($saufId !== null) {
            $requete->where('id', '!=', $saufId);
        }

        $requete->update(['is_default' => false, 'updated_at' => now()]);
    }
}
