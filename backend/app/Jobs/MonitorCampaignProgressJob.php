<?php

namespace App\Jobs;

use App\Models\ScrapingCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 19.7 — MonitorCampaignProgressJob.
 *
 * Re-dispatched toutes les 60s tant que la campagne est running.
 *
 *  1) Recompute companies_created via count(distinct companies.id) joined sur runs.campaign_id
 *     (best-effort : si la table companies n'a pas de campaign_id direct on count via runs.company_id).
 *  2) Recompute duration_seconds_used = sum(EXTRACT(EPOCH FROM (finished_at - started_at))) sur les runs de la campagne.
 *  3) Recompute runs_completed = runs ayant status ∈ (success|completed|failed|cancelled).
 *  4) Si shouldAutoPause() ≠ null → pause auto (status=paused, paused_reason=…).
 *  5) Si runs_completed === runs_total et runs_total > 0 → status=completed.
 *  6) Sinon re-dispatch dans 60s.
 */
class MonitorCampaignProgressJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 60;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        /** @var ScrapingCampaign|null $campaign */
        $campaign = ScrapingCampaign::find($this->campaignId);
        if (! $campaign) {
            return;
        }
        if (! in_array($campaign->status, ['running'], true)) {
            return;
        }

        // 1+3) Aggrégats côté runs
        $aggregates = ['runs_completed' => 0, 'companies_created' => 0, 'duration_seconds_used' => 0];

        try {
            if (Schema::hasTable('scraper_runs')) {
                $row = DB::selectOne(
                    "SELECT
                        COUNT(*) FILTER (WHERE status IN ('success','completed','failed','cancelled'))::INTEGER AS runs_completed,
                        COALESCE(SUM(
                            CASE WHEN started_at IS NOT NULL AND finished_at IS NOT NULL
                                 THEN EXTRACT(EPOCH FROM (finished_at - started_at))
                                 ELSE 0 END
                        ),0)::INTEGER AS duration_seconds_used,
                        COUNT(DISTINCT company_id) FILTER (WHERE company_id IS NOT NULL)::INTEGER AS companies_created
                     FROM scraper_runs
                     WHERE campaign_id = ?",
                    [$campaign->id]
                );
                if ($row) {
                    $aggregates['runs_completed']        = (int) ($row->runs_completed ?? 0);
                    $aggregates['duration_seconds_used'] = (int) ($row->duration_seconds_used ?? 0);
                    $aggregates['companies_created']     = (int) ($row->companies_created ?? 0);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('MonitorCampaignProgressJob: aggregates failed', [
                'campaign_id' => $campaign->id,
                'exception'   => $e->getMessage(),
            ]);
        }

        $campaign->update($aggregates);
        $campaign->refresh();

        // 4) Auto-pause ?
        $reason = $campaign->shouldAutoPause();
        if ($reason !== null) {
            $campaign->update([
                'status'        => 'paused',
                'paused_at'     => now(),
                'paused_reason' => $reason,
            ]);
            Log::info('Campaign auto-paused', [
                'campaign_id' => $campaign->id,
                'reason'      => $reason,
            ]);
            return;
        }

        // 5) Tous les runs terminés ?
        if ($campaign->runs_total > 0 && $campaign->runs_completed >= $campaign->runs_total) {
            $campaign->update([
                'status'      => 'completed',
                'finished_at' => now(),
            ]);
            return;
        }

        // 6) Sinon, re-self-dispatch
        self::dispatch($campaign->id)->delay(now()->addSeconds(60));
    }
}
