<?php

namespace App\Http\Controllers\Api;

use App\Events\ScraperRunCancelled;
use App\Http\Requests\StoreScrapingCampaignRequest;
use App\Http\Requests\UpdateScrapingCampaignRequest;
use App\Http\Resources\ScrapingCampaignResource;
use App\Jobs\LaunchCampaignJob;
use App\Jobs\MonitorCampaignProgressJob;
use App\Models\ScrapingCampaign;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19.7 — Campagnes de scraping (CRUD + lifecycle + stats).
 *
 * Endpoints :
 *  GET    /campaigns                → liste paginated avec filters status + search
 *  POST   /campaigns                → create draft
 *  GET    /campaigns/{id}           → detail + runs nested preview
 *  PUT    /campaigns/{id}           → update (status=draft uniquement)
 *  DELETE /campaigns/{id}           → soft delete
 *  POST   /campaigns/{id}/start     → status=running, dispatch LaunchCampaignJob
 *  POST   /campaigns/{id}/pause     → status=paused
 *  POST   /campaigns/{id}/resume    → status=running, dispatch monitor
 *  POST   /campaigns/{id}/cancel    → status=cancelled, cancel runs en cours
 *  GET    /campaigns/{id}/stats     → live aggregation
 */
class ScrapingCampaignsController extends ApiController
{
    /**
     * @OA\Get(path="/campaigns", tags={"Campaigns"}, summary="Liste paginée des campagnes",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="status", in="query", @OA\Schema(type="string")),
     *     @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        if (! Schema::hasTable('scraping_campaigns')) {
            return $this->ok(['data' => [], 'meta' => ['total' => 0]]);
        }

        $perPage = min(100, max(1, (int) $r->query('per_page', 25)));
        $workspaceId = $this->workspaceIdOrNull();

        try {
            $query = ScrapingCampaign::query()
                ->orderByRaw("CASE status
                    WHEN 'running' THEN 1
                    WHEN 'paused' THEN 2
                    WHEN 'scheduled' THEN 3
                    WHEN 'draft' THEN 4
                    WHEN 'completed' THEN 5
                    WHEN 'failed' THEN 6
                    WHEN 'cancelled' THEN 7
                    ELSE 8 END")
                ->orderByDesc('created_at');

            if ($workspaceId !== null) {
                $query->where('workspace_id', $workspaceId);
            }
            if ($status = $r->query('status')) {
                $query->where('status', $status);
            }
            if ($search = $r->query('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'ILIKE', "%{$search}%")
                      ->orWhere('description', 'ILIKE', "%{$search}%");
                });
            }

            $page = $query->paginate($perPage);

            return $this->ok([
                'data' => ScrapingCampaignResource::collection($page->items()),
                'meta' => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('campaigns.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'meta' => ['total' => 0], 'degraded' => true]);
        }
    }

    /**
     * @OA\Post(path="/campaigns", tags={"Campaigns"}, summary="Crée une campagne (status=draft ou scheduled)",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=201, description="Created"))
     */
    public function store(StoreScrapingCampaignRequest $r): JsonResponse
    {
        $validated = $r->validated();
        $workspaceId = $this->workspaceIdOrNull();
        $user = $r->user();

        if ($workspaceId === null || ! $user) {
            return response()->json(['error' => 'no_workspace', 'message' => 'Workspace courant requis.'], 422);
        }

        $status = isset($validated['scheduled_at']) && $validated['scheduled_at'] !== null
            ? 'scheduled'
            : 'draft';

        $campaign = ScrapingCampaign::create([
            'workspace_id'            => $workspaceId,
            'created_by'              => $user->id,
            'name'                    => $validated['name'],
            'description'             => $validated['description'] ?? null,
            'status'                  => $status,
            'sources'                 => $validated['sources'],
            'zones'                   => $validated['zones'],
            'max_companies'           => $validated['max_companies'] ?? 1000,
            'max_duration_minutes'    => $validated['max_duration_minutes'] ?? 180,
            'max_requests_per_minute' => $validated['max_requests_per_minute'] ?? 30,
            'per_source_limits'       => $validated['per_source_limits'] ?? null,
            'scheduled_at'            => $validated['scheduled_at'] ?? null,
            'expires_at'              => $validated['expires_at'] ?? null,
        ]);

        return response()->json(new ScrapingCampaignResource($campaign), 201);
    }

    /**
     * @OA\Get(path="/campaigns/{id}", tags={"Campaigns"}, summary="Detail",
     *     security={{"sanctumCookie":{}}})
     */
    public function show(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        $campaign->load(['runs' => function ($q) {
            $q->latest('id')->limit(20);
        }]);
        return $this->ok(new ScrapingCampaignResource($campaign));
    }

    /**
     * @OA\Put(path="/campaigns/{id}", tags={"Campaigns"}, summary="Update (status=draft uniquement)",
     *     security={{"sanctumCookie":{}}})
     */
    public function update(UpdateScrapingCampaignRequest $r, ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        if ($campaign->status !== 'draft') {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Modification autorisée uniquement en status=draft (actuel : {$campaign->status}).",
                'status'  => $campaign->status,
            ], 422);
        }

        $campaign->update($r->validated());
        return $this->ok(new ScrapingCampaignResource($campaign->fresh()));
    }

    /**
     * @OA\Delete(path="/campaigns/{id}", tags={"Campaigns"}, summary="Soft delete",
     *     security={{"sanctumCookie":{}}})
     */
    public function destroy(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        $campaign->delete();
        return $this->ok(['deleted' => true]);
    }

    /**
     * @OA\Post(path="/campaigns/{id}/start", tags={"Campaigns"}, summary="Démarre une campagne draft|scheduled",
     *     security={{"sanctumCookie":{}}})
     */
    public function start(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        if (! $campaign->canStart()) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Impossible de démarrer une campagne au statut '{$campaign->status}'.",
                'status'  => $campaign->status,
            ], 422);
        }

        $campaign->update([
            'status'        => 'running',
            'started_at'    => $campaign->started_at ?? now(),
            'paused_reason' => null,
            'paused_at'     => null,
        ]);

        LaunchCampaignJob::dispatch($campaign->id);

        return $this->ok(new ScrapingCampaignResource($campaign->fresh()));
    }

    /**
     * @OA\Post(path="/campaigns/{id}/pause", tags={"Campaigns"}, summary="Pause une campagne running",
     *     security={{"sanctumCookie":{}}})
     */
    public function pause(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        if (! $campaign->canPause()) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Impossible de mettre en pause une campagne au statut '{$campaign->status}'.",
                'status'  => $campaign->status,
            ], 422);
        }

        $campaign->update([
            'status'        => 'paused',
            'paused_at'     => now(),
            'paused_reason' => 'manual',
        ]);

        // Marque les runs pending de cette campagne en cancelled (best-effort)
        if (Schema::hasTable('scraper_runs')) {
            DB::table('scraper_runs')
                ->where('campaign_id', $campaign->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled', 'finished_at' => now(), 'error' => 'Pause campagne']);
        }

        return $this->ok(new ScrapingCampaignResource($campaign->fresh()));
    }

    /**
     * @OA\Post(path="/campaigns/{id}/resume", tags={"Campaigns"}, summary="Reprend une campagne paused",
     *     security={{"sanctumCookie":{}}})
     */
    public function resume(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        if (! $campaign->canResume()) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Impossible de reprendre une campagne au statut '{$campaign->status}'.",
                'status'  => $campaign->status,
            ], 422);
        }

        $campaign->update([
            'status'        => 'running',
            'paused_at'     => null,
            'paused_reason' => null,
        ]);

        // Si auto-pause sur quota_companies/duration, refuser le resume implicite
        // serait plus prudent, mais pour le MVP on autorise (l'user reprend en connaissance de cause).
        MonitorCampaignProgressJob::dispatch($campaign->id);

        return $this->ok(new ScrapingCampaignResource($campaign->fresh()));
    }

    /**
     * @OA\Post(path="/campaigns/{id}/cancel", tags={"Campaigns"}, summary="Annule une campagne",
     *     security={{"sanctumCookie":{}}})
     */
    public function cancel(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }
        if (! $campaign->canCancel()) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Impossible d'annuler une campagne au statut '{$campaign->status}'.",
                'status'  => $campaign->status,
            ], 422);
        }

        $campaign->update([
            'status'      => 'cancelled',
            'finished_at' => now(),
        ]);

        // Annule tous les runs pending/running de cette campagne
        if (Schema::hasTable('scraper_runs')) {
            $runsToCancel = DB::table('scraper_runs')
                ->where('campaign_id', $campaign->id)
                ->whereIn('status', ['pending', 'running'])
                ->pluck('id');

            DB::table('scraper_runs')
                ->whereIn('id', $runsToCancel)
                ->update([
                    'status'      => 'cancelled',
                    'finished_at' => now(),
                    'error'       => 'Campagne annulée',
                ]);

            foreach ($runsToCancel as $runId) {
                try {
                    Redis::setex('cancelled:scraper-run:' . $runId, 3600, '1');
                } catch (\Throwable $e) {
                    Log::warning('campaigns.cancel: Redis flag failed', [
                        'run_id'    => $runId,
                        'exception' => $e->getMessage(),
                    ]);
                }
                event(new ScraperRunCancelled(
                    workspaceId: (string) $campaign->workspace_id,
                    scraperRunId: (int) $runId,
                    reason: 'campaign_cancel',
                ));
            }
        }

        return $this->ok(new ScrapingCampaignResource($campaign->fresh()));
    }

    /**
     * @OA\Get(path="/campaigns/{id}/stats", tags={"Campaigns"}, summary="Stats live d'une campagne",
     *     security={{"sanctumCookie":{}}})
     */
    public function stats(ScrapingCampaign $campaign): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($campaign)) {
            abort(404);
        }

        $perSource = [];
        $perZone = [];
        $lastEvents = [];

        try {
            if (Schema::hasTable('scraper_runs')) {
                $perSource = DB::table('scraper_runs')
                    ->selectRaw("source,
                                 COUNT(*) AS total,
                                 COUNT(*) FILTER (WHERE status = 'running') AS running,
                                 COUNT(*) FILTER (WHERE status IN ('success','completed')) AS success,
                                 COUNT(*) FILTER (WHERE status = 'failed') AS failed,
                                 COUNT(DISTINCT company_id) FILTER (WHERE company_id IS NOT NULL) AS companies")
                    ->where('campaign_id', $campaign->id)
                    ->groupBy('source')
                    ->get();

                $lastEvents = DB::table('scraper_runs')
                    ->select('id', 'source', 'status', 'started_at', 'finished_at', 'error', 'request_payload')
                    ->where('campaign_id', $campaign->id)
                    ->orderByDesc('id')
                    ->limit(30)
                    ->get();
            }
        } catch (\Throwable $e) {
            Log::warning('campaigns.stats failed', [
                'campaign_id' => $campaign->id,
                'exception'   => $e->getMessage(),
            ]);
        }

        // Rate live : companies créées dans les 60 dernières secondes (basé sur runs.finished_at)
        $companiesLastMinute = 0;
        try {
            if (Schema::hasTable('scraper_runs')) {
                $companiesLastMinute = (int) DB::table('scraper_runs')
                    ->where('campaign_id', $campaign->id)
                    ->where('finished_at', '>=', now()->subMinute())
                    ->count();
            }
        } catch (\Throwable) {
            // Best-effort
        }

        return $this->ok([
            'campaign'             => new ScrapingCampaignResource($campaign->fresh()),
            'per_source'           => $perSource,
            'per_zone'             => $perZone,
            'last_events'          => $lastEvents,
            'companies_per_minute' => $companiesLastMinute,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function belongsToCurrentWorkspace(ScrapingCampaign $campaign): bool
    {
        $workspaceId = $this->workspaceIdOrNull();
        if ($workspaceId === null) {
            return true;
        }
        return (string) $campaign->workspace_id === (string) $workspaceId;
    }

    private function workspaceIdOrNull(): ?string
    {
        if (app()->bound('workspace.id')) {
            $id = app('workspace.id');
            return $id !== null && $id !== '' ? (string) $id : null;
        }
        $user = request()->user();
        if ($user && $user->current_workspace_id) {
            return (string) $user->current_workspace_id;
        }
        return null;
    }
}
