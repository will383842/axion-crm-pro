<?php

namespace App\Jobs;

use App\Models\ScrapingCampaign;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sprint 19.7 — LaunchCampaignJob.
 *
 * Orchestre la création de N scraper_runs pour une campagne :
 *  - 1 run par (zone × source) lié au campaign_id
 *  - délai entre dispatch selon max_requests_per_minute (anti-blacklist)
 *  - met à jour runs_total + dispatch MonitorCampaignProgressJob
 *
 * Best-effort : si une zone est invalide ou une source down, on log et on continue,
 * la campagne s'auto-pause si quota duration atteint.
 */
class LaunchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    public function __construct(public readonly int $campaignId) {}

    public function handle(): void
    {
        /** @var ScrapingCampaign|null $campaign */
        $campaign = ScrapingCampaign::find($this->campaignId);
        if (! $campaign) {
            Log::warning('LaunchCampaignJob: campaign not found', ['campaign_id' => $this->campaignId]);
            return;
        }
        if ($campaign->status !== 'running') {
            Log::info('LaunchCampaignJob: campaign no longer running, skip', [
                'campaign_id' => $campaign->id,
                'status'      => $campaign->status,
            ]);
            return;
        }

        $sources = $campaign->sources ?? [];
        $zones = $campaign->zones ?? [];
        $rpm = max(1, (int) $campaign->max_requests_per_minute);
        $delayPerRequestSec = (int) ceil(60.0 / $rpm);

        $perCampaignLimit = (int) ceil($campaign->max_companies / max(1, count($zones) * count($sources)));
        $perCampaignLimit = max(10, min(1000, $perCampaignLimit));

        $runsTotal = 0;
        $offsetSeconds = 0;

        foreach ($zones as $zone) {
            $zoneType = $zone['type'] ?? null;
            $zoneCode = $zone['code'] ?? null;
            if (! $zoneType || ! $zoneCode) {
                continue;
            }

            foreach ($sources as $source) {
                // Pour l'instant on ne route INSEE/LaunchZoneScrapingJob que sur des départements.
                // Les autres sources reçoivent un ScraperRun pending — picked-up par les workers Node BullMQ.
                $department = $zoneType === 'department' ? $zoneCode : null;

                if ($source === 'insee' && $department !== null) {
                    LaunchZoneScrapingJob::dispatch(
                        (string) $campaign->workspace_id,
                        $department,
                        null,
                        null,
                        $perCampaignLimit,
                    )->delay(now()->addSeconds($offsetSeconds));
                    $runsTotal++;
                } else {
                    // Crée un run pending qu'un worker Node BullMQ pourra récupérer.
                    // Le worker honorera campaign_id pour rattacher les companies créées.
                    try {
                        \App\Models\ScraperRun::create([
                            'workspace_id'    => $campaign->workspace_id,
                            'campaign_id'    => $campaign->id,
                            'source'         => $source,
                            'status'         => 'pending',
                            'started_at'     => null,
                            'request_payload' => [
                                'type'        => 'campaign',
                                'campaign_id' => $campaign->id,
                                'zone'        => $zone,
                                'limit'       => $perCampaignLimit,
                            ],
                        ]);
                        $runsTotal++;
                    } catch (\Throwable $e) {
                        Log::warning('LaunchCampaignJob: run create failed', [
                            'campaign_id' => $campaign->id,
                            'source'      => $source,
                            'zone'        => $zone,
                            'exception'   => $e->getMessage(),
                        ]);
                    }
                }

                $offsetSeconds += $delayPerRequestSec;
            }
        }

        $campaign->update(['runs_total' => $runsTotal]);

        // Démarre le moniteur de progression (re-self-dispatch toutes les 60s).
        MonitorCampaignProgressJob::dispatch($campaign->id)->delay(now()->addSeconds(30));
    }
}
