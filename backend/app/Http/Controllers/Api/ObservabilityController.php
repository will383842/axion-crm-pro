<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Scraping\GooglePlacesClient;
use Illuminate\Database\Query\Builder;
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
                'hunter_quota_month' => $this->countHunterMonth($workspaceId),
                'google_places_quota' => $this->googlePlacesQuotaSummary(),
                'archive_reasons' => $this->countArchiveReasons($workspaceId),
                'audience_failures_7d' => $this->countAudienceFailures7d($workspaceId),
                'recent_events' => $this->recentBusinessEvents($workspaceId),
                'site_sync' => $this->siteSyncReceptions($workspaceId),
                'outbound' => $this->outboundBacklog(),
            ],
        ]);
    }

    /**
     * Lot L5 — COMPTEUR DE RÉCEPTIONS (plan §2.9). Le site tient le tableau de
     * santé de l'émission ; le CRM expose en miroir ce qu'il a REÇU. Sans ce
     * miroir, un canal muet ressemble exactement à un canal calme : c'est la
     * leçon IndexNow (un agrégateur qui ne relaie rien dans un job vert).
     *
     * Chaque événement ingéré laisse une activité `external_ref = site:event:*`
     * (l'idempotence de L2 repose déjà dessus) : c'est donc la trace de
     * réception, sans table de compteurs à maintenir.
     *
     * Scopé au workspace COURANT, comme le reste du résumé : l'étanchéité
     * business / vivier vaut aussi pour les compteurs (un commercial n'a pas à
     * déduire le volume de candidatures reçues).
     *
     * @return array{ingested_today: int, ingested_7d: int, last_ingested_at: ?string}
     */
    private function siteSyncReceptions(string $workspaceId): array
    {
        try {
            $base = static fn (): Builder => DB::table('activities')
                ->where('workspace_id', $workspaceId)
                ->where('external_ref', 'LIKE', 'site:event:%');

            $last = $base()->max('created_at');

            return [
                'ingested_today' => (int) $base()->where('created_at', '>=', now()->startOfDay())->count(),
                'ingested_7d' => (int) $base()->where('created_at', '>=', now()->subDays(7))->count(),
                'last_ingested_at' => $last === null ? null : (string) $last,
            ];
        } catch (\Throwable $e) {
            return ['ingested_today' => 0, 'ingested_7d' => 0, 'last_ingested_at' => null];
        }
    }

    /**
     * Lot L5 — santé de la mini-outbox CRM → site. `gave_up` est l'état qui
     * doit se voir : une opposition abandonnée est une divergence RGPD
     * durable, pas un incident technique mineur.
     *
     * Table GLOBALE (infrastructure, sans workspace_id) : le compteur ne se
     * scope pas.
     *
     * @return array{pending: int, gave_up: int}
     */
    private function outboundBacklog(): array
    {
        try {
            $rows = DB::table('crm_outbound_events')
                ->select('status', DB::raw('COUNT(*) AS c'))
                ->whereIn('status', ['pending', 'failed', 'gave_up'])
                ->groupBy('status')
                ->pluck('c', 'status')
                ->all();

            return [
                // Un `failed` est encore en attente de rejeu : il compte dans le
                // backlog, sans quoi un backlog en échec paraîtrait vide.
                'pending' => (int) ($rows['pending'] ?? 0) + (int) ($rows['failed'] ?? 0),
                'gave_up' => (int) ($rows['gave_up'] ?? 0),
            ];
        } catch (\Throwable $e) {
            return ['pending' => 0, 'gave_up' => 0];
        }
    }

    /**
     * Sprint H13 — KPI quota Google Places mensuel (couvre tous workspaces,
     * c'est un compteur global de l'API key partagée côté infra).
     *
     * @return array{used: int, soft_limit: int, percent: float, pending_companies: int}
     */
    private function googlePlacesQuotaSummary(): array
    {
        try {
            $client = app(GooglePlacesClient::class);
            $used = $client->currentMonthUsage();
            $limit = $client->monthlyQuotaLimit();
            $pending = (int) DB::table('companies')
                ->whereRaw("(signals->'google_places_pending') IS NOT NULL")
                ->whereRaw("(signals->'google_places'->>'enriched_at') IS NULL")
                ->count();
        } catch (\Throwable $e) {
            $used = 0;
            $limit = 11500;
            $pending = 0;
        }

        return [
            'used' => $used,
            'soft_limit' => $limit,
            'percent' => $limit > 0 ? min(100, round($used / $limit * 100, 1)) : 0,
            'pending_companies' => $pending,
        ];
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
            $monthEnd = now()->endOfMonth();
            $count = (int) DB::table('email_verification_logs')
                ->where('workspace_id', $workspaceId)
                ->where('provider', 'hunter')
                ->whereBetween('verified_at', [$monthStart, $monthEnd])
                ->count();
        } catch (\Throwable $e) {
            $count = 0;  // table peut être absente avant migrate
        }

        return [
            'used' => $count,
            'soft_limit' => 1000,  // plan Starter Hunter par défaut, ajuster via env si Growth
            'percent' => $count > 0 ? min(100, round($count / 1000 * 100, 1)) : 0,
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
                    'id' => $r->id,
                    'action' => $r->action,
                    'resource_type' => $r->resource_type,
                    'resource_id' => $r->resource_id,
                    'context' => is_string($r->context) ? json_decode($r->context, true) : $r->context,
                    'created_at' => $r->created_at,
                ])
                ->all();
        } catch (\Throwable $e) {
            return [];
        }
    }
}
