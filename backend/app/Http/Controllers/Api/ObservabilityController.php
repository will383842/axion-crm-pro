<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Sprint H4 — Dashboard observability backend.
 *
 * GET /api/v1/observability/summary → KPI cards + recent activity
 *
 * Toutes les queries sont déjà scopées par workspace via RLS PG
 * (SetCurrentWorkspace middleware pose app.current_workspace_id).
 */
class ObservabilityController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $workspaceId = (string) ($request->user()->current_workspace_id ?? '');

        return response()->json([
            'data' => [
                'waterfall_errors_24h' => $this->countWaterfallErrors24h($workspaceId),
                'hunter_quota_month'   => $this->countHunterMonth($workspaceId),
                'archive_reasons'      => $this->countArchiveReasons($workspaceId),
                'audience_failures_7d' => $this->countAudienceFailures7d($workspaceId),
                'recent_events'        => $this->recentBusinessEvents($workspaceId),
            ],
        ]);
    }

    private function countWaterfallErrors24h(string $workspaceId): int
    {
        return (int) DB::table('scraper_runs')
            ->where('workspace_id', $workspaceId)
            ->where('status', 'failed')
            ->where('created_at', '>', now()->subDay())
            ->count();
    }

    private function countHunterMonth(string $workspaceId): array
    {
        try {
            // Sprint H2 verif fix (2026-05-18) : BETWEEN sur début/fin de mois courant
            // au lieu de date_trunc(timestamptz) — utilise l'index range scan
            // (workspace_id, verified_at) sans avoir besoin d'index fonctionnel IMMUTABLE.
            $monthStart = now()->startOfMonth();
            $monthEnd   = now()->endOfMonth();
            $count = (int) DB::table('email_verification_logs')
                ->where('workspace_id', $workspaceId)
                ->where('provider', 'hunter')
                ->whereBetween('verified_at', [$monthStart, $monthEnd])
                ->count();
        } catch (\Throwable $e) {
            $count = 0;  // table peut être absente avant migrate
        }
        return [
            'used'       => $count,
            'soft_limit' => 1000,  // plan Starter Hunter par défaut, ajuster via env si Growth
            'percent'    => $count > 0 ? min(100, round($count / 1000 * 100, 1)) : 0,
        ];
    }

    /** @return array<string, int> */
    private function countArchiveReasons(string $workspaceId): array
    {
        $rows = DB::table('companies')
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('archive_reason')
            ->select('archive_reason', DB::raw('COUNT(*) AS c'))
            ->groupBy('archive_reason')
            ->pluck('c', 'archive_reason')
            ->all();
        return array_map(static fn ($v) => (int) $v, $rows);
    }

    private function countAudienceFailures7d(string $workspaceId): int
    {
        try {
            return (int) DB::table('business_events')
                ->where('workspace_id', $workspaceId)
                ->where('action', 'audience.refresh.failed')
                ->where('created_at', '>', now()->subDays(7))
                ->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** @return list<array<string, mixed>> */
    private function recentBusinessEvents(string $workspaceId): array
    {
        try {
            return DB::table('business_events')
                ->where('workspace_id', $workspaceId)
                ->orderByDesc('created_at')
                ->limit(50)
                ->get(['id', 'action', 'resource_type', 'resource_id', 'context', 'created_at'])
                ->map(fn ($r) => [
                    'id'            => $r->id,
                    'action'        => $r->action,
                    'resource_type' => $r->resource_type,
                    'resource_id'   => $r->resource_id,
                    'context'       => is_string($r->context) ? json_decode($r->context, true) : $r->context,
                    'created_at'    => $r->created_at,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
