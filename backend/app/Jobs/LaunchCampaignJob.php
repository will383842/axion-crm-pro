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
 * Orchestre la création de N scraper_runs pour une campagne :
 *  - 1 run par (zone × source DE DÉCOUVERTE) lié au campaign_id
 *  - délai entre dispatch selon max_requests_per_minute (anti-blacklist)
 *  - met à jour runs_total + dispatch MonitorCampaignProgressJob
 *
 * Sprint Pipeline 360° :
 * - Sources DE DÉCOUVERTE (créent des companies) :
 *     insee, france_travail, google_maps, pages_jaunes
 * - Sources D'ENRICHISSEMENT (s'appliquent automatiquement à chaque company
 *   via le WaterfallOrchestrator, jamais dispatchées ici) :
 *     annuaire-entreprises, bodacc, ban
 *   → si elles apparaissent dans campaign->sources (legacy), elles sont skip + log.
 *
 * Best-effort : si une zone est invalide ou une source down, on log et on continue,
 * la campagne s'auto-pause si quota duration atteint.
 */
class LaunchCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 600;

    /** Sources de découverte traitées par LaunchZoneScrapingJob (Laravel queue). */
    private const DISCOVERY_SOURCES_BACKEND = ['insee', 'france_travail'];

    /** Sources de découverte traitées par Node BullMQ workers. */
    private const DISCOVERY_SOURCES_NODE = ['google_maps', 'pages_jaunes'];

    /** Sources d'enrichissement (jamais dispatchées comme discovery). */
    private const ENRICHMENT_ONLY_SOURCES = ['annuaire-entreprises', 'bodacc', 'ban'];

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

        $discoverySources = array_values(array_filter($sources, fn ($s) =>
            in_array($s, self::DISCOVERY_SOURCES_BACKEND, true)
            || in_array($s, self::DISCOVERY_SOURCES_NODE, true)
        ));

        $denominator = max(1, count($zones) * max(1, count($discoverySources)));
        $perCampaignLimit = (int) ceil($campaign->max_companies / $denominator);
        $perCampaignLimit = max(10, min(1000, $perCampaignLimit));

        $runsTotal = 0;
        $offsetSeconds = 0;
        $mockScrapers = (bool) env('MOCK_SCRAPERS', true);

        foreach ($zones as $zone) {
            $zoneType = $zone['type'] ?? null;
            $zoneCode = $zone['code'] ?? null;
            if (! $zoneType || ! $zoneCode) {
                continue;
            }

            foreach ($sources as $source) {
                // Sources d'enrichissement : jamais dispatchées comme discovery
                if (in_array($source, self::ENRICHMENT_ONLY_SOURCES, true)) {
                    Log::info('LaunchCampaignJob: enrichment-only source skipped (waterfall handles it)', [
                        'campaign_id' => $campaign->id, 'source' => $source,
                    ]);
                    continue;
                }

                // Pour l'instant, seules les zones type=department sont supportées
                if ($zoneType !== 'department') {
                    Log::info('LaunchCampaignJob: zone type unsupported, skip', [
                        'campaign_id' => $campaign->id, 'zone_type' => $zoneType,
                    ]);
                    continue;
                }
                $department = (string) $zoneCode;

                if (in_array($source, self::DISCOVERY_SOURCES_BACKEND, true)) {
                    // Dispatch direct via LaunchZoneScrapingJob (queue Laravel)
                    LaunchZoneScrapingJob::dispatch(
                        (string) $campaign->workspace_id,
                        $department,
                        null,
                        null,
                        $perCampaignLimit,
                        $campaign->id,
                        $source,
                    )->delay(now()->addSeconds($offsetSeconds));
                    $runsTotal++;
                } elseif (in_array($source, self::DISCOVERY_SOURCES_NODE, true)) {
                    // Sources Phase B (Node BullMQ via DispatchScrapeJob)
                    if ($mockScrapers) {
                        // Mode mock : crée un run skipped pour traçabilité UI
                        try {
                            \App\Models\ScraperRun::create([
                                'workspace_id'    => $campaign->workspace_id,
                                'campaign_id'     => $campaign->id,
                                'source'          => $source,
                                'status'          => 'cancelled',
                                'started_at'      => now(),
                                'finished_at'     => now(),
                                'error'           => 'MOCK_SCRAPERS=true: Phase B Webshare non activée',
                                'request_payload' => [
                                    'type'        => 'campaign',
                                    'campaign_id' => $campaign->id,
                                    'zone'        => $zone,
                                    'limit'       => $perCampaignLimit,
                                ],
                            ]);
                            // Compte aussi comme run_completed pour ne pas bloquer le monitor
                            \Illuminate\Support\Facades\DB::table('scraping_campaigns')
                                ->where('id', $campaign->id)
                                ->update([
                                    'runs_completed' => \Illuminate\Support\Facades\DB::raw('runs_completed + 1'),
                                    'updated_at'     => now(),
                                ]);
                            $runsTotal++;
                        } catch (\Throwable $e) {
                            Log::warning('LaunchCampaignJob: mock run create failed', [
                                'campaign_id' => $campaign->id, 'source' => $source,
                                'exception'   => $e->getMessage(),
                            ]);
                        }
                    } else {
                        // Prod : dispatch via LaunchZoneScrapingJob qui appellera dispatchNodeWorker()
                        LaunchZoneScrapingJob::dispatch(
                            (string) $campaign->workspace_id,
                            $department,
                            null,
                            null,
                            $perCampaignLimit,
                            $campaign->id,
                            $source,
                        )->delay(now()->addSeconds($offsetSeconds));
                        $runsTotal++;
                    }
                } else {
                    Log::info('LaunchCampaignJob: unknown source, skip', [
                        'campaign_id' => $campaign->id, 'source' => $source,
                    ]);
                    continue;
                }

                $offsetSeconds += $delayPerRequestSec;
            }
        }

        $campaign->update(['runs_total' => $runsTotal]);

        // Démarre le moniteur de progression (re-self-dispatch toutes les 60s).
        MonitorCampaignProgressJob::dispatch($campaign->id)->delay(now()->addSeconds(30));
    }
}
