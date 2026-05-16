<?php

namespace App\Http\Controllers\Api;

use App\Jobs\LaunchZoneScrapingJob;
use App\Services\Rotations\ZoneRotator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class CoverageController extends ApiController
{
    public function __construct(private readonly ZoneRotator $rotator) {}

    public function index(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['cells' => []]);
        }

        $level = $r->query('level', 'department');
        $cacheKey = "coverage:{$workspaceId}:{$level}";

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

                'city' => DB::select(<<<SQL
                    SELECT ci.code_insee AS code, ci.name, ci.department, ci.population,
                           ST_Y(ci.centroid) AS lat, ST_X(ci.centroid) AS lon,
                           SUM(cm.company_count) AS total
                    FROM coverage_matrix_cells cm
                    JOIN cities ci ON LEFT(cm.postcode, 2) = ci.department
                    WHERE cm.workspace_id = ?
                    GROUP BY ci.code_insee, ci.name, ci.department, ci.population, ci.centroid
                    ORDER BY total DESC NULLS LAST
                    LIMIT 500
                SQL, [$workspaceId]),

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

        return $this->ok(['level' => $level, 'cells' => $cells]);
    }

    public function nextZone(Request $r): JsonResponse
    {
        $workspaceId = app()->bound('workspace.id') ? app('workspace.id') : null;
        if (! $workspaceId) {
            return $this->ok(['zone' => null]);
        }
        $zone = $this->rotator->pickNextZone((string) $workspaceId, $r->query('preferred_dept'));
        return $this->ok(['zone' => $zone]);
    }

    public function launch(Request $r): JsonResponse
    {
        $validated = $r->validate([
            'department'    => ['required', 'string', 'max:3'],
            'naf'           => ['nullable', 'string', 'max:5'],
            'size_category' => ['nullable', 'string', 'max:32'],
            'limit'         => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        LaunchZoneScrapingJob::dispatch(
            (string) (app()->bound('workspace.id') ? app('workspace.id') : ''),
            $validated['department'],
            $validated['naf'] ?? null,
            $validated['size_category'] ?? null,
            (int) ($validated['limit'] ?? 100),
        );

        return $this->ok(['queued' => true]);
    }

    public function showCell(int $cell): JsonResponse
    {
        return $this->ok(['id' => $cell]);
    }
}
