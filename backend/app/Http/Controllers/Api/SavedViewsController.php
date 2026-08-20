<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

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
    /** Des filtres sauvegardes : quelques dizaines par personne, jamais mille. */
    private const PLAFOND = 100;

    /**
     * @OA\Get(path="/saved-views", tags={"SavedViews"}, summary="Liste vues sauvegardées (filtres companies/contacts)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="entity", in="query", @OA\Schema(type="string")),
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
     * @OA\Post(path="/saved-views", tags={"SavedViews"}, summary="Crée vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function store(Request $r): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Get(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Show vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function show(int $savedView): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Put(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Update vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, int $savedView): JsonResponse { return $this->notImplemented('10'); }

    /**
     * @OA\Delete(path="/saved-views/{savedView}", tags={"SavedViews"}, summary="Delete vue (Sprint 10)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="savedView", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function destroy(int $savedView): JsonResponse { return $this->notImplemented('10'); }
}
