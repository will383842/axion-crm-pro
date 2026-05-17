<?php

namespace App\Jobs;

use App\Contracts\InseeClient;
use App\Models\Company;
use App\Models\ScraperRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lance le scraping d'une zone géo+secteur+taille via INSEE :
 * 1. Crée un ScraperRun (status=running) lié à campaign_id si fourni
 * 2. Recherche INSEE selon critères → liste SIREN
 * 3. Upsert companies + dispatch EnrichCompanyJob pour chacune
 * 4. Met à jour le ScraperRun (status=success/failed) + le compteur campagne
 *
 * Sprint 19.7.1 — ajout campaign_id pour rattacher les runs à la campagne parente
 * et permettre le MonitorCampaignProgressJob de compter correctement.
 */
class LaunchZoneScrapingJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 1800;

    public function __construct(
        public readonly string $workspaceId,
        public readonly string $department,
        public readonly ?string $naf,
        public readonly ?string $sizeCategory,
        public readonly int $limit,
        public readonly ?int $campaignId = null,
    ) {}

    public function handle(InseeClient $insee): void
    {
        // 1. Tracker : créer un ScraperRun en running, attaché à la campagne si fournie
        $run = ScraperRun::create([
            'workspace_id'    => $this->workspaceId,
            'campaign_id'     => $this->campaignId,
            'source'          => 'insee',
            'status'          => 'running',
            'started_at'      => now(),
            'request_payload' => [
                'type'        => $this->campaignId ? 'campaign' : 'coverage_launch',
                'campaign_id' => $this->campaignId,
                'department'  => $this->department,
                'naf'         => $this->naf,
                'limit'       => $this->limit,
            ],
        ]);

        $companiesCreated = 0;
        $companiesFound = 0;

        try {
            $results = $insee->searchByCriteria([
                'department' => $this->department,
                'naf'        => $this->naf,
                'limit'      => $this->limit,
            ]);
            $companiesFound = count($results);

            foreach ($results as $data) {
                $company = Company::query()->updateOrCreate(
                    ['workspace_id' => $this->workspaceId, 'siren' => $data->siren],
                    [
                        'denomination'    => $data->denomination,
                        'naf'             => $data->naf ?? $this->naf,
                        'legal_form'      => $data->legalForm,
                        'effectif_range'  => $data->effectifRange,
                        'size_category'   => $this->sizeCategory,
                        'discovery_source'=> $this->campaignId ? 'campaign' : 'coverage_launch',
                    ],
                );
                // Si la company vient d'être créée par cette campagne, incrémenter le compteur.
                if ($company->wasRecentlyCreated) {
                    $companiesCreated++;
                }
                EnrichCompanyJob::dispatch($company->id);
            }

            // 2. Update run : success + payload résultats
            $run->update([
                'status'           => 'success',
                'finished_at'      => now(),
                'response_payload' => [
                    'companies_found'   => $companiesFound,
                    'companies_created' => $companiesCreated,
                ],
            ]);

            // 3. Update campagne parente si liée (compteur companies_created)
            if ($this->campaignId !== null && $companiesCreated > 0) {
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'companies_created' => DB::raw("companies_created + {$companiesCreated}"),
                        'runs_completed'    => DB::raw('runs_completed + 1'),
                        'updated_at'        => now(),
                    ]);
            } elseif ($this->campaignId !== null) {
                // Pas de nouvelles companies mais le run a quand même tourné → marquer completed
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'runs_completed' => DB::raw('runs_completed + 1'),
                        'updated_at'     => now(),
                    ]);
            }
        } catch (\Throwable $e) {
            Log::error('LaunchZoneScrapingJob failed', [
                'workspace_id' => $this->workspaceId,
                'campaign_id'  => $this->campaignId,
                'department'   => $this->department,
                'error'        => $e->getMessage(),
            ]);
            $run->update([
                'status'        => 'failed',
                'finished_at'   => now(),
                'error_message' => mb_substr($e->getMessage(), 0, 500),
            ]);
            // Incrémente quand même runs_completed sur la campagne (sinon coincée)
            if ($this->campaignId !== null) {
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'runs_completed' => DB::raw('runs_completed + 1'),
                        'updated_at'     => now(),
                    ]);
            }
            throw $e;
        }
    }
}
