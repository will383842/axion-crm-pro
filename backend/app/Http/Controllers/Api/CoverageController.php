<?php

namespace App\Http\Controllers\Api;

use App\Jobs\EnrichCompanyJob;
use App\Jobs\LaunchZoneScrapingJob;
use App\Models\Company;
use App\Services\Rotations\ZoneRotator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CoverageController extends ApiController
{
    public function __construct(private readonly ZoneRotator $rotator) {}

    /**
     * @OA\Get(path="/coverage", tags={"Coverage"}, summary="Matrice de couverture France (région / département / ville)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="level", in="query", @OA\Schema(type="string", enum={"region","department","city"}, default="department")),
     *     @OA\Response(response=200, description="Cells groupées par niveau"))
     */
    public function index(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['cells' => []]);
        }

        $level = $r->query('level', 'department');

        // Sprint 18.9 — defensive : si la table n'existe pas encore (env fraîche),
        // on retourne une liste vide plutôt que 500.
        if (! Schema::hasTable('coverage_matrix_cells')) {
            return $this->ok(['level' => $level, 'cells' => []]);
        }

        $cacheKey = "coverage:{$workspaceId}:{$level}";

        try {
            $cells = Cache::remember($cacheKey, 60, function () use ($workspaceId, $level) {
                return match ($level) {
                    'region' => DB::select(<<<SQL
                        SELECT d.region_code AS code, r.name AS name,
                               SUM(cm.company_count) AS total,
                               SUM(cm.complete_count) AS complete,
                               SUM(cm.partial_count)  AS partial
                        FROM coverage_matrix_cells cm
                        JOIN departments d ON d.code = cm.dept_code
                        JOIN regions r ON r.code = d.region_code
                        WHERE cm.workspace_id = ?
                        GROUP BY d.region_code, r.name
                        ORDER BY total DESC NULLS LAST
                    SQL, [$workspaceId]),

                    'city' => $this->queryCityCells($workspaceId),

                    default => DB::select(<<<SQL
                        SELECT cm.dept_code AS code, d.name AS name, d.region_code,
                               SUM(cm.company_count) AS total,
                               SUM(cm.complete_count) AS complete,
                               SUM(cm.partial_count)  AS partial
                        FROM coverage_matrix_cells cm
                        JOIN departments d ON d.code = cm.dept_code
                        WHERE cm.workspace_id = ?
                        GROUP BY cm.dept_code, d.name, d.region_code
                        ORDER BY total DESC NULLS LAST
                    SQL, [$workspaceId]),
                };
            });
        } catch (\Throwable $e) {
            // Sprint 18.9 — log + fallback empty plutôt que 500 (RLS denied, PostGIS missing, etc.)
            Log::error('coverage.index failed', [
                'workspace_id' => $workspaceId,
                'level'        => $level,
                'exception'    => $e->getMessage(),
            ]);
            report($e);
            return $this->ok(['level' => $level, 'cells' => [], 'degraded' => true]);
        }

        return $this->ok(['level' => $level, 'cells' => $cells]);
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
            return DB::select(<<<SQL
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
        return DB::select(<<<SQL
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
     *     @OA\Parameter(name="preferred_dept", in="query", @OA\Schema(type="string")),
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
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"department"},
     *         @OA\Property(property="department", type="string", maxLength=3),
     *         @OA\Property(property="naf", type="string", maxLength=5),
     *         @OA\Property(property="size_category", type="string"),
     *         @OA\Property(property="limit", type="integer", minimum=1, maximum=1000),
     *         @OA\Property(property="enrich", type="boolean", description="false = récupérer seulement (pas d'enrichissement chaîné)"))),
     *     @OA\Response(response=200, description="Job queué"))
     */
    public function launch(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'department'    => ['required', 'string', 'max:3'],
            'naf'           => ['nullable', 'string', 'max:5'],
            'size_category' => ['nullable', 'string', 'max:32'],
            'limit'         => ['nullable', 'integer', 'min:1', 'max:1000'],
            'enrich'        => ['nullable', 'boolean'],
        ]);

        LaunchZoneScrapingJob::dispatch(
            workspaceId: (string) (app()->bound('workspace.id') ? app('workspace.id') : ''),
            department: $validated['department'],
            naf: $validated['naf'] ?? null,
            sizeCategory: $validated['size_category'] ?? null,
            limit: (int) ($validated['limit'] ?? 100),
            enrich: $validated['enrich'] ?? true,
        );

        return $this->ok(['queued' => true]);
    }

    /**
     * @OA\Post(path="/coverage/enrich", tags={"Coverage"}, summary="Enrichit les entreprises DÉJÀ récupérées d'un département (bouton « Enrichir »)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\RequestBody(required=true, @OA\JsonContent(
     *         required={"department"},
     *         @OA\Property(property="department", type="string", maxLength=3),
     *         @OA\Property(property="size_category", type="string"),
     *         @OA\Property(property="naf", type="string", maxLength=5),
     *         @OA\Property(property="only_pending", type="boolean", description="true (défaut) = seulement les non-enrichies"))),
     *     @OA\Response(response=200, description="Jobs d'enrichissement queués"))
     */
    public function enrich(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'department'    => ['required', 'string', 'max:3'],
            'size_category' => ['nullable', 'string', 'max:32'],
            'naf'           => ['nullable', 'string', 'max:5'],
            'only_pending'  => ['nullable', 'boolean'],
            'limit'         => ['nullable', 'integer', 'min:1', 'max:50000'],
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
        $query->select('id')->chunkById(500, function ($companies) use (&$queued, $cap) {
            foreach ($companies as $company) {
                if ($queued >= $cap) {
                    return false;
                }
                EnrichCompanyJob::dispatch($company->id);
                $queued++;
            }
        });

        return $this->ok(['queued' => $queued]);
    }

    /**
     * @OA\Get(path="/coverage/cells/{cell}", tags={"Coverage"}, summary="Détail d'une cellule de couverture",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="cell", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"))
     */
    public function showCell(int $cell): JsonResponse
    {
        return $this->ok(['id' => $cell]);
    }
}
