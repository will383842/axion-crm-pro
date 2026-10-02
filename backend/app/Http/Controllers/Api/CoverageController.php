<?php

namespace App\Http\Controllers\Api;

use App\Jobs\EnrichCompanyJob;
use App\Jobs\LaunchZoneScrapingJob;
use App\Models\Company;
use App\Services\Dedup\DeduplicationService;
use App\Services\Rotations\ZoneRotator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CoverageController extends ApiController
{
    /** La vue est rafraîchie toutes les heures : 10 min de cache suffisent. */
    public const CACHE_SECONDES = 600;

    /**
     * Le code département d'une cellule, depuis son code postal.
     *
     * La vue porte `dept_code = LEFT(postcode, 2)`, ce qui est faux pour la
     * Corse (`20…` → `2A`/`2B`) et l'outre-mer (`97…` → `971`…`976`) : ces
     * 212 245 fiches ne rejoignaient aucun département et disparaissaient de
     * la carte. Corse : codes postaux 200xx-201xx = Corse-du-Sud (2A), 202xx
     * à 206xx = Haute-Corse (2B).
     */
    public const DEPARTEMENT_SQL = "CASE
            WHEN cm.postcode LIKE '97%' THEN LEFT(cm.postcode, 3)
            WHEN cm.postcode LIKE '20%' THEN CASE WHEN cm.postcode < '20200' THEN '2A' ELSE '2B' END
            ELSE cm.dept_code END";

    /**
     * `Schema::hasTable()` ne voit pas les vues matérialisées (cf. `index`).
     */
    public static function matriceExiste(): bool
    {
        try {
            $ligne = DB::selectOne("SELECT to_regclass('coverage_matrix_cells') IS NOT NULL AS existe");

            return (bool) ($ligne->existe ?? false);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * `SUM()` rend un `numeric`, que PDO livre en CHAÎNE : côté écran,
     * `s + c.total` aurait CONCATÉNÉ les totaux. On rend des entiers.
     *
     * @param  array<int, object>  $lignes
     * @return array<int, array<string, mixed>>
     */
    private static function entiers(array $lignes): array
    {
        return array_map(static function (object $l): array {
            $l = (array) $l;
            foreach (['total', 'complete', 'partial', 'population'] as $champ) {
                if (array_key_exists($champ, $l) && $l[$champ] !== null) {
                    $l[$champ] = (int) $l[$champ];
                }
            }

            return $l;
        }, $lignes);
    }

    public function __construct(
        private readonly ZoneRotator $rotator,
        private readonly DeduplicationService $dedup,
    ) {}

    /**
     * @OA\Get(path="/coverage", tags={"Coverage"}, summary="Matrice de couverture France (région / département / ville)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="level", in="query", @OA\Schema(type="string", enum={"region","department","city"}, default="department")),
     *
     *     @OA\Response(response=200, description="Cells groupées par niveau"))
     */
    public function index(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['cells' => []]);
        }

        $level = $r->query('level', 'department');

        // 🔴 2026-10-02 — LA CARTE AFFICHAIT 0 % SUR 4,3 M D'ENTREPRISES.
        //
        // Le garde était `Schema::hasTable('coverage_matrix_cells')`. Or
        // `coverage_matrix_cells` est une VUE MATÉRIALISÉE, et `hasTable()` de
        // Laravel 12 ne regarde que `pg_class.relkind IN ('r','p')` : il rend
        // FAUX pour une vue matérialisée (`relkind = 'm'`), même peuplée. La
        // route répondait donc toujours `{"level":"department","cells":[]}`
        // (33 octets, mesuré en production), alors que la vue portait
        // 1 082 282 cellules pour 4 346 269 fiches. `existe()` interroge
        // `to_regclass`, qui voit tous les genres de relation.
        if (! self::matriceExiste()) {
            return $this->ok(['level' => $level, 'cells' => []]);
        }

        $cacheKey = "coverage:v2:{$workspaceId}:{$level}";

        try {
            // La vue est rafraîchie toutes les heures (`coverage:refresh-matrix`) :
            // la recalculer à chaque affichage (≈ 400 ms) n'apporte rien.
            $cells = Cache::remember($cacheKey, self::CACHE_SECONDES, function () use ($workspaceId, $level) {
                $dept = self::DEPARTEMENT_SQL;

                $lignes = match ($level) {
                    'region' => DB::select(<<<SQL
                        SELECT d.region_code AS code, r.name AS name,
                               SUM(x.company_count) AS total,
                               SUM(x.complete_count) AS complete,
                               SUM(x.partial_count)  AS partial
                        FROM (SELECT {$dept} AS dept, cm.company_count, cm.complete_count, cm.partial_count
                              FROM coverage_matrix_cells cm WHERE cm.workspace_id = ?) x
                        JOIN departments d ON d.code = x.dept
                        JOIN regions r ON r.code = d.region_code
                        GROUP BY d.region_code, r.name
                        ORDER BY total DESC NULLS LAST
                    SQL, [$workspaceId]),

                    'city' => $this->queryCityCells($workspaceId),

                    default => DB::select(<<<SQL
                        SELECT x.dept AS code, d.name AS name, d.region_code,
                               SUM(x.company_count) AS total,
                               SUM(x.complete_count) AS complete,
                               SUM(x.partial_count)  AS partial
                        FROM (SELECT {$dept} AS dept, cm.company_count, cm.complete_count, cm.partial_count
                              FROM coverage_matrix_cells cm WHERE cm.workspace_id = ?) x
                        JOIN departments d ON d.code = x.dept
                        GROUP BY x.dept, d.name, d.region_code
                        ORDER BY total DESC NULLS LAST
                    SQL, [$workspaceId]),
                };

                return self::entiers($lignes);
            });
        } catch (\Throwable $e) {
            // Sprint 18.9 — log + fallback empty plutôt que 500 (RLS denied, PostGIS missing, etc.)
            Log::error('coverage.index failed', [
                'workspace_id' => $workspaceId,
                'level' => $level,
                'exception' => $e->getMessage(),
            ]);
            report($e);

            return $this->ok(['level' => $level, 'cells' => [], 'degraded' => true]);
        }

        // Relecture A09 de #284 : « dont N au score ≥ 50 » suit la même règle
        // que l'accueil. La part de scores périmés vient du calcul de l'accueil
        // (déjà en cache) ; inconnue → null, et l'écran ne montre pas le chiffre.
        $accueil = Cache::get(DashboardController::cle((string) $workspaceId));
        $perimes = is_array($accueil) && is_numeric($accueil['quality_a_recalculer_pct'] ?? null)
            ? (float) $accueil['quality_a_recalculer_pct']
            : null;

        return $this->ok(['level' => $level, 'cells' => $cells, 'quality_a_recalculer_pct' => $perimes]);
    }

    /**
     * Sprint 18.9 — requête city avec détection PostGIS (ST_X/ST_Y).
     * Si l'extension n'est pas installée sur la DB, on retombe sur une variante
     * sans coordonnées plutôt que de crasher.
     */
    private function queryCityCells(int|string $workspaceId): array
    {
        $hasPostgis = false;
        try {
            $row = DB::select("SELECT 1 AS ok FROM pg_extension WHERE extname = 'postgis' LIMIT 1");
            $hasPostgis = ! empty($row);
        } catch (\Throwable $e) {
            $hasPostgis = false;
        }

        if ($hasPostgis) {
            return DB::select(<<<'SQL'
                SELECT ci.code_insee AS code, ci.name, ci.department, ci.population,
                       ST_Y(ci.centroid) AS lat, ST_X(ci.centroid) AS lon,
                       SUM(cm.company_count) AS total
                FROM coverage_matrix_cells cm
                JOIN cities ci ON LEFT(cm.postcode, 2) = ci.department
                WHERE cm.workspace_id = ?
                GROUP BY ci.code_insee, ci.name, ci.department, ci.population, ci.centroid
                ORDER BY total DESC NULLS LAST
                LIMIT 500
            SQL, [$workspaceId]);
        }

        // PostGIS absent — pas de coordonnées géo, mais le reste fonctionne.
        return DB::select(<<<'SQL'
            SELECT ci.code_insee AS code, ci.name, ci.department, ci.population,
                   NULL::float AS lat, NULL::float AS lon,
                   SUM(cm.company_count) AS total
            FROM coverage_matrix_cells cm
            JOIN cities ci ON LEFT(cm.postcode, 2) = ci.department
            WHERE cm.workspace_id = ?
            GROUP BY ci.code_insee, ci.name, ci.department, ci.population
            ORDER BY total DESC NULLS LAST
            LIMIT 500
        SQL, [$workspaceId]);
    }

    /**
     * @OA\Get(path="/coverage/next-zone", tags={"Coverage"}, summary="Sélectionne la prochaine zone à scraper (rotation déterministe)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="preferred_dept", in="query", @OA\Schema(type="string")),
     *
     *     @OA\Response(response=200, description="Zone sélectionnée"))
     */
    public function nextZone(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['zone' => null]);
        }
        try {
            $zone = $this->rotator->pickNextZone((string) $workspaceId, $r->query('preferred_dept'));

            return $this->ok(['zone' => $zone]);
        } catch (\Throwable $e) {
            Log::error('coverage.nextZone failed', ['exception' => $e->getMessage()]);
            report($e);

            return $this->ok(['zone' => null, 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/coverage/launch", tags={"Coverage"}, summary="Lance un scraping ciblé département/NAF/taille",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"department"},
     *
     *         @OA\Property(property="department", type="string", maxLength=3),
     *         @OA\Property(property="naf", type="string", maxLength=5),
     *         @OA\Property(property="size_category", type="string"),
     *         @OA\Property(property="limit", type="integer", minimum=1, maximum=1000),
     *         @OA\Property(property="enrich", type="boolean", description="false = récupérer seulement (pas d'enrichissement chaîné)"))),
     *
     *     @OA\Response(response=200, description="Job queué"))
     */
    public function launch(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'department' => ['required', 'string', 'max:3'],
            'naf' => ['nullable', 'string', 'max:5'],
            'size_category' => ['nullable', 'string', 'max:32'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enrich' => ['nullable', 'boolean'],
        ]);

        $workspaceId = (string) (app()->bound('workspace.id') ? app('workspace.id') : '');

        LaunchZoneScrapingJob::dispatch(
            workspaceId: $workspaceId,
            department: $validated['department'],
            naf: $validated['naf'] ?? null,
            sizeCategory: $validated['size_category'] ?? null,
            limit: (int) ($validated['limit'] ?? 100),
            enrich: $validated['enrich'] ?? true,
        );

        // 🔴 LE COOLDOWN DE ZONE N'ÉTAIT JAMAIS POSÉ.
        //
        // `ZoneRotator::pickNextZone()` filtre sur
        // `cz.cooldown_until IS NULL OR cz.cooldown_until < now()`, en `LEFT JOIN`
        // sur `coverage_zones`. Mais `markZoneAttempted()` n'avait AUCUN
        // appelant : la table restait vide, le `LEFT JOIN` rendait toujours
        // `NULL`, et la condition était donc **tautologiquement vraie**.
        // Le garde anti-répétition était nul et non avenu — `next-zone` pouvait
        // proposer indéfiniment la même cellule, et rien n'empêchait de
        // re-scraper la même zone en boucle (coût proxy et quota).
        // Constaté le 2026-08-16.
        //
        // On marque ICI et pas dans `nextZone()` : `next-zone` ne fait que
        // SUGGÉRER une zone, l'écran peut l'interroger plusieurs fois sans rien
        // lancer. C'est `launch()` qui attaque réellement la zone — c'est donc
        // lui qui doit consommer le cooldown.
        // ⚠️ ON VÉRIFIE AVANT D'ÉCRIRE, ON NE RATTRAPE PAS APRÈS.
        //
        // `coverage_zones.department` porte une clé étrangère vers
        // `departments`, alors que cette route ne valide `department` que par
        // `string|max:3`. Un code inexistant fait donc échouer l'insertion.
        //
        // Un `try/catch` ne suffit PAS : sous PostgreSQL, une instruction en
        // échec AVORTE toute la transaction en cours (`SQLSTATE 25P02`) — tout
        // ce qui suit échoue jusqu'au rollback, y compris hors du `try`. Le
        // rattrapage arriverait trop tard dès que cet appel se retrouve
        // enveloppé dans une transaction. Constaté en test le 2026-08-16.
        //
        // On teste donc la précondition. Le cooldown est une optimisation, pas
        // une condition de correction : un département inconnu ne doit pas
        // empêcher le lancement, qui passait très bien avant ce marquage.
        $departementConnu = $workspaceId !== ''
            && DB::table('departments')->where('code', $validated['department'])->exists();

        if ($departementConnu) {
            $this->dedup->markZoneAttempted(
                $workspaceId,
                $validated['department'],
                $validated['naf'] ?? null,
                $validated['size_category'] ?? null,
            );
        } elseif ($workspaceId !== '') {
            Log::warning('coverage.launch : cooldown non posé, département inconnu', [
                'department' => $validated['department'],
            ]);
        }

        return $this->ok(['queued' => true]);
    }

    /**
     * @OA\Post(path="/coverage/enrich", tags={"Coverage"}, summary="Enrichit les entreprises DÉJÀ récupérées d'un département (bouton « Enrichir »)",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"department"},
     *
     *         @OA\Property(property="department", type="string", maxLength=3),
     *         @OA\Property(property="size_category", type="string"),
     *         @OA\Property(property="naf", type="string", maxLength=5),
     *         @OA\Property(property="only_pending", type="boolean", description="true (défaut) = seulement les non-enrichies"))),
     *
     *     @OA\Response(response=200, description="Jobs d'enrichissement queués"))
     */
    public function enrich(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'department' => ['required', 'string', 'max:3'],
            'size_category' => ['nullable', 'string', 'max:32'],
            'naf' => ['nullable', 'string', 'max:5'],
            'only_pending' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50000'],
        ]);

        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['queued' => 0]);
        }

        // Enrichit les entreprises DÉJÀ en base pour ce département (pas de re-découverte).
        // Filtre géo sur department_code (colonne dénormalisée), pas postcode.
        $query = Company::query()
            ->where('workspace_id', $workspaceId)
            ->where('department_code', $validated['department']);

        if (($validated['only_pending'] ?? true) === true) {
            $query->whereNull('enriched_at');
        }
        if (! empty($validated['size_category'])) {
            $query->where('size_category', $validated['size_category']);
        }
        if (! empty($validated['naf'])) {
            $query->where('naf', $validated['naf']);
        }

        $cap = (int) ($validated['limit'] ?? 50000);
        $queued = 0;
        $query->select('id')->chunkById(500, function ($companies) use (&$queued, $cap, $workspaceId) {
            foreach ($companies as $company) {
                if ($queued >= $cap) {
                    return false;
                }
                dispatch((new EnrichCompanyJob($company->id))->pourEspace($workspaceId));
                $queued++;
            }
        });

        return $this->ok(['queued' => $queued]);
    }

    /**
     * @OA\Get(path="/coverage/cells/{cell}", tags={"Coverage"}, summary="Détail d'une cellule de couverture",
     *     security={{"sanctumCookie":{}}},
     *
     *     @OA\Parameter(name="cell", in="path", required=true, @OA\Schema(type="integer")),
     *
     *     @OA\Response(response=200, description="OK"))
     */
    public function showCell(int $cell): JsonResponse
    {
        return $this->ok(['id' => $cell]);
    }
}
