<?php

namespace App\Services\Rotations;

use Illuminate\Support\Facades\DB;

/**
 * Sélection « prochaine zone à attaquer » selon coverage_matrix_cells +
 * coverage_zones cooldown 24h. Advisory lock pour éviter les concurrent picks.
 */
class ZoneRotator
{
    public function pickNextZone(string $workspaceId, ?string $preferredDept = null): ?array
    {
        // Advisory lock 32 bits sur workspace_id hash + 'zones'
        $lockKey = crc32($workspaceId . ':zones');
        $locked = (bool) DB::selectOne("SELECT pg_try_advisory_xact_lock(?)", [$lockKey])->pg_try_advisory_xact_lock;
        if (! $locked) {
            return null;
        }

        $row = DB::selectOne(<<<SQL
            SELECT
                cm.dept_code AS department,
                cm.naf,
                cm.size_category,
                cm.company_count
            FROM coverage_matrix_cells cm
            LEFT JOIN coverage_zones cz
                ON cz.workspace_id = cm.workspace_id
                AND cz.department = cm.dept_code
                AND cz.naf IS NOT DISTINCT FROM cm.naf
                AND cz.size_category IS NOT DISTINCT FROM cm.size_category
            WHERE cm.workspace_id = ?
              AND (cz.cooldown_until IS NULL OR cz.cooldown_until < now())
              AND (? IS NULL OR cm.dept_code = ?)
            ORDER BY cm.company_count ASC NULLS FIRST
            LIMIT 1
        SQL, [$workspaceId, $preferredDept, $preferredDept]);

        return $row ? (array) $row : null;
    }
}
