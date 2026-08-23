<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Scraping\GooglePlacesClient;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Sprint H4 — Dashboard observability backend.
 *
 * GET /api/v1/observability/summary → KPI cards + recent activity
 *
 * Toutes les queries sont déjà scopées par workspace via RLS PG
 * (SetCurrentWorkspace middleware pose app.current_workspace_id).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * F39-007 — « RIEN À SIGNALER » ET « JE N'AI PAS PU REGARDER » NE DOIVENT PAS
 * AVOIR LA MÊME APPARENCE.
 *
 * Mesure du 2026-08-22 : six blocs d'interception de ce fichier rendaient une
 * valeur neutre (0, [], null) et le fichier n'importait même pas `Log` — aucun
 * des six avalements ne laissait donc la moindre trace. C'est l'écran de SANTÉ
 * du produit : une rubrique tombée y ressemblait exactement à une rubrique
 * calme, et personne ne pouvait le savoir, ni sur le tableau de bord, ni dans
 * les journaux. (Leçon déjà payée ailleurs : un agrégateur qui ne relaie rien
 * dans un job vert.)
 *
 * CE QUI EST FAIT ICI : chaque `catch` journalise en `warning`, avec le nom de
 * la rubrique. La panne devient consultable, et alertable.
 *
 * CE QUI N'EST PAS FAIT, ET POURQUOI : le rapport proposait aussi d'ajouter un
 * marqueur d'état (`degraded: true` / `status: 'indisponible'`) au JSON et de
 * faire afficher « non mesuré » au tableau de bord. Cela change le CONTRAT de
 * l'endpoint et l'apparence d'un écran — une décision de produit, pas un
 * correctif. À trancher avec Will avant de toucher au JSON.
 *
 * ⚠️ `countWaterfallErrors24h()` et `countArchiveReasons()` n'ont, elles, AUCUN
 * filet : une panne y remonte en 500. C'est DÉLIBÉRÉ et ce n'est pas un oubli —
 * les envelopper d'un `catch` qui rend 0 ajouterait deux zéros muets de plus,
 * c'est-à-dire le défaut qu'on répare. Une rubrique qui échoue bruyamment vaut
 * mieux qu'une rubrique qui ment doucement.
 * ─────────────────────────────────────────────────────────────────────────────
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
            // F39-007 — sans cette ligne, un canal d'ingestion MUET rendait
            // exactement les mêmes zéros qu'un canal simplement calme.
            Log::warning('observability.site_sync indisponible', ['exception' => $e->getMessage()]);

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
            // F39-007 — `gave_up = 0` est la valeur qu'on ESPÈRE : la rendre en
            // avalant l'erreur transforme une divergence RGPD en bonne nouvelle.
            Log::warning('observability.outbound indisponible', ['exception' => $e->getMessage()]);

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
            // F39-007 — un quota à 0 % affiché parce que le client Google Places
            // ne répond pas est un feu vert fabriqué : il faut qu'il se voie.
            Log::warning('observability.google_places_quota indisponible', ['exception' => $e->getMessage()]);

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
            // F39-007 — le commentaire « table peut être absente avant migrate »
            // dit l'intention, il ne la trace pas : après la migration, la même
            // branche avale une vraie panne d'index avec le même silence.
            Log::warning('observability.hunter_quota_month indisponible', ['exception' => $e->getMessage()]);

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
            // F39-007 — « 0 échec de rafraîchissement d'audience sur 7 jours »
            // est précisément ce qu'on veut lire : ne l'écrivons pas à l'aveugle.
            Log::warning('observability.audience_failures_7d indisponible', ['exception' => $e->getMessage()]);

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
            // F39-007 — un flux d'activité vide raconte « il ne se passe rien »,
            // ce qui est la lecture la plus rassurante et la moins verifiable.
            Log::warning('observability.recent_events indisponible', ['exception' => $e->getMessage()]);

            return [];
        }
    }
}
