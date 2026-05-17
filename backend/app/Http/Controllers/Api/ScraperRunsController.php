<?php

namespace App\Http\Controllers\Api;

use App\Events\ScraperRunCancelled;
use App\Jobs\LaunchZoneScrapingJob;
use App\Models\ScraperRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Schema;

class ScraperRunsController extends ApiController
{
    /**
     * @OA\Get(path="/scraper-runs", tags={"Scraping"}, summary="Historique scraper runs",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Response(response=200, description="OK"))
     */
    public function index(Request $r): JsonResponse
    {
        // Sprint 18.9 — defensive
        if (! Schema::hasTable('scraper_runs')) {
            return $this->ok(['data' => [], 'meta' => ['total' => 0]]);
        }

        try {
            $workspaceId = $this->workspaceIdOrNull();
            $query = ScraperRun::query()->orderByDesc('started_at');
            if ($workspaceId !== null) {
                $query->where('workspace_id', $workspaceId);
            }
            $page = $query->paginate(25);

            return $this->ok([
                'data' => $page->items(),
                'meta' => [
                    'total'        => $page->total(),
                    'per_page'     => $page->perPage(),
                    'current_page' => $page->currentPage(),
                    'last_page'    => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('scraper-runs.index failed', ['exception' => $e->getMessage()]);
            report($e);
            return $this->ok(['data' => [], 'meta' => ['total' => 0], 'degraded' => true]);
        }
    }

    /**
     * @OA\Get(path="/scraper-runs/{run}", tags={"Scraping"}, summary="Détail d'un run",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="OK"))
     */
    public function show(ScraperRun $run): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($run)) {
            // 404 plutôt que 403 : ne pas leak l'existence du run d'un autre workspace.
            abort(404);
        }
        return $this->ok($run);
    }

    /**
     * @OA\Post(path="/scraper-runs/{run}/cancel", tags={"Scraping"}, summary="Annule un run en cours",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=200, description="Cancelled"),
     *     @OA\Response(response=404, description="Not found / cross-workspace"),
     *     @OA\Response(response=422, description="Run dans un état non annulable"))
     */
    public function cancel(ScraperRun $run): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($run)) {
            abort(404);
        }

        if (! in_array($run->status, ['pending', 'running'], true)) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Impossible d'annuler un run au statut '{$run->status}'.",
                'status'  => $run->status,
            ], 422);
        }

        $run->update([
            'status'      => 'cancelled',
            'finished_at' => now(),
            'error'       => $run->error ?: 'Annulé par utilisateur.',
        ]);

        // Flag Redis lu par les workers Node (BullMQ) pour interrompre le job en cours.
        // TTL 1h : suffisant pour qu'un worker en cours détecte l'annulation.
        try {
            Redis::setex('cancelled:scraper-run:' . $run->id, 3600, '1');
        } catch (\Throwable $e) {
            Log::warning('scraper-runs.cancel: Redis flag failed', [
                'run_id'    => $run->id,
                'exception' => $e->getMessage(),
            ]);
        }

        // Broadcast pour rafraîchir l'UI temps réel.
        event(new ScraperRunCancelled(
            workspaceId: (string) $run->workspace_id,
            scraperRunId: (int) $run->id,
            reason: 'manual_cancel',
        ));

        return $this->ok($run->fresh());
    }

    /**
     * @OA\Post(path="/scraper-runs/{run}/retry", tags={"Scraping"}, summary="Relance un run échoué/annulé",
     *     security={{"sanctumCookie":{}}},
     *     @OA\Parameter(name="run", in="path", required=true, @OA\Schema(type="integer")),
     *     @OA\Response(response=201, description="Nouveau run créé"),
     *     @OA\Response(response=404, description="Not found / cross-workspace"),
     *     @OA\Response(response=422, description="Run dans un état non relançable"))
     */
    public function retry(ScraperRun $run): JsonResponse
    {
        if (! $this->belongsToCurrentWorkspace($run)) {
            abort(404);
        }

        if (! in_array($run->status, ['failed', 'cancelled'], true)) {
            return response()->json([
                'error'   => 'invalid_state',
                'message' => "Impossible de relancer un run au statut '{$run->status}'.",
                'status'  => $run->status,
            ], 422);
        }

        // Crée un nouveau run pending avec les mêmes paramètres de requête.
        $newRun = DB::transaction(function () use ($run) {
            return ScraperRun::create([
                'workspace_id'    => $run->workspace_id,
                'company_id'      => $run->company_id,
                'source'          => $run->source,
                'status'          => 'pending',
                'started_at'      => now(),
                'request_payload' => $run->request_payload,
            ]);
        });

        // Re-dispatch le job approprié si on a un payload de zone-launch.
        // Sinon on laisse le worker correspondant le récupérer via le flux normal.
        $payload = is_array($run->request_payload) ? $run->request_payload : [];
        if (isset($payload['type']) && $payload['type'] === 'zone-launch') {
            LaunchZoneScrapingJob::dispatch(
                (string) $run->workspace_id,
                (string) ($payload['department'] ?? ''),
                $payload['naf'] ?? null,
                $payload['size_category'] ?? null,
                (int) ($payload['limit'] ?? 100),
            );
        }

        return response()->json($newRun, 201);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Vérifie qu'un run appartient au workspace courant.
     * Tolérant si workspace.id n'est pas bound (tests/dev) : ne bloque pas.
     */
    private function belongsToCurrentWorkspace(ScraperRun $run): bool
    {
        $workspaceId = $this->workspaceIdOrNull();
        if ($workspaceId === null) {
            return true;
        }
        return (string) $run->workspace_id === (string) $workspaceId;
    }

    private function workspaceIdOrNull(): ?string
    {
        if (app()->bound('workspace.id')) {
            $id = app('workspace.id');
            return $id !== null && $id !== '' ? (string) $id : null;
        }
        // Fallback via user authentifié si le middleware workspace n'a pas tourné.
        $user = request()->user();
        if ($user && $user->current_workspace_id) {
            return (string) $user->current_workspace_id;
        }
        return null;
    }
}
