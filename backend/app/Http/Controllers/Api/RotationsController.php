<?php

namespace App\Http\Controllers\Api;

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
     * @OA\Put(path="/rotations/{rotation}", tags={"Rotations"}, summary="Update rotation (Sprint 4)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="rotation", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=501, description="Not implemented"))
     */
    public function update(Request $r, int $rotation): JsonResponse
    {
        return $this->notImplemented('4');
    }
}
