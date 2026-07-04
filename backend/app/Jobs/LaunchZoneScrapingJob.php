<?php

namespace App\Jobs;

use App\Contracts\InseeClient;
use App\Models\Company;
use App\Models\ScraperRun;
use App\Services\FranceTravail\FranceTravailDiscoveryClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Lance le scraping de découverte pour une zone géo via une source donnée.
 *
 * Sources supportées (paramètre `$source`) :
 *  - 'insee'           : InseeClient->searchByCriteria(department, naf, limit) (default)
 *  - 'france_travail'  : FranceTravailDiscoveryClient->searchEntreprisesByDept(dept, limit)
 *  - 'google_maps'     : skip silencieux si MOCK_SCRAPERS=true, sinon DispatchScrapeJob Node BullMQ
 *  - 'pages_jaunes'    : idem
 *
 * Flow :
 *  1. Crée un ScraperRun (status=running) avec source dynamique
 *  2. Discovery selon $source → liste DTO entreprises
 *  3. Upsert companies + dispatch EnrichCompanyJob pour chacune
 *  4. Met à jour ScraperRun (status=success/failed) + compteurs campagne
 *
 * Backward-compat : $source='insee' par défaut, anciens callers /coverage/launch
 * continuent de fonctionner sans changement.
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
        public readonly string $source = 'insee',
        public readonly bool $enrich = true,
    ) {}

    public function handle(InseeClient $insee, FranceTravailDiscoveryClient $ftDiscovery): void
    {
        $run = ScraperRun::create([
            'workspace_id'    => $this->workspaceId,
            'campaign_id'     => $this->campaignId,
            'source'          => $this->source,
            'status'          => 'running',
            'started_at'      => now(),
            'request_payload' => [
                'type'        => $this->campaignId ? 'campaign' : 'coverage_launch',
                'campaign_id' => $this->campaignId,
                'department'  => $this->department,
                'naf'         => $this->naf,
                'limit'       => $this->limit,
                'source'      => $this->source,
            ],
        ]);

        $companiesCreated = 0;
        $companiesFound = 0;
        $companiesNew = 0;
        $companiesRefreshed = 0;

        try {
            $results = $this->discoverEntreprises($insee, $ftDiscovery);
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
                        'discovery_source'=> $this->source,
                    ],
                );
                if ($company->wasRecentlyCreated) {
                    $companiesNew++;
                } else {
                    $companiesRefreshed++;
                }
                // Enrichissement chaîné seulement si demandé (bouton « Récupérer »
                // seul → enrich=false ; « Enrichir » séparé via /coverage/enrich).
                if ($this->enrich) {
                    EnrichCompanyJob::dispatch($company->id);
                }
            }
            $companiesCreated = $companiesNew + $companiesRefreshed;

            $run->update([
                'status'           => 'success',
                'finished_at'      => now(),
                'response_payload' => [
                    'companies_found'     => $companiesFound,
                    'companies_processed' => $companiesCreated,
                    'companies_new'       => $companiesNew,
                    'companies_refreshed' => $companiesRefreshed,
                    'source'              => $this->source,
                ],
            ]);

            if ($this->campaignId !== null && $companiesCreated > 0) {
                DB::table('scraping_campaigns')
                    ->where('id', $this->campaignId)
                    ->update([
                        'companies_created' => DB::raw("companies_created + {$companiesCreated}"),
                        'runs_completed'    => DB::raw('runs_completed + 1'),
                        'updated_at'        => now(),
                    ]);
            } elseif ($this->campaignId !== null) {
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
                'source'       => $this->source,
                'error'        => $e->getMessage(),
            ]);
            $run->update([
                'status'      => 'failed',
                'finished_at' => now(),
                'error'       => mb_substr($e->getMessage(), 0, 500),
            ]);
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

    /**
     * Dispatch vers le bon client selon $this->source.
     *
     * @return array<int, \App\Data\Sources\InseeCompanyData>
     */
    private function discoverEntreprises(InseeClient $insee, FranceTravailDiscoveryClient $ftDiscovery): array
    {
        return match ($this->source) {
            'insee' => $insee->searchByCriteria([
                'department' => $this->department,
                'naf'        => $this->naf,
                'limit'      => $this->limit,
            ]),
            'france_travail' => $ftDiscovery->searchEntreprisesByDept($this->department, $this->limit),
            'google_maps', 'pages_jaunes' => $this->dispatchNodeWorker(),
            default => throw new \RuntimeException("Unknown discovery source: {$this->source}"),
        };
    }

    /**
     * Pour les sources Node (Playwright) : si MOCK_SCRAPERS=true on retourne []
     * silencieusement (run = success vide). Sinon on enqueue le scrape Node mais
     * dans tous les cas LaunchZoneScrapingJob ne reçoit pas les résultats Node
     * (asynchrone via /internal/scraper-result).
     *
     * @return array<int, \App\Data\Sources\InseeCompanyData>
     */
    private function dispatchNodeWorker(): array
    {
        if ((bool) env('MOCK_SCRAPERS', true)) {
            Log::info('LaunchZoneScrapingJob: MOCK_SCRAPERS=true, skipping Node worker', [
                'source' => $this->source, 'department' => $this->department,
            ]);
            return [];
        }
        // Phase B (production) : enqueue via DispatchScrapeJob — résultats arriveront
        // de manière asynchrone côté /internal/scraper-result et créeront leurs propres
        // Company + ScraperRun. On ne bloque pas ici.
        DispatchScrapeJob::dispatch(
            companyId: 0,  // synthétique : pas de company source pour une zone discovery
            source: str_replace('_', '-', $this->source),
            context: [
                'discovery_zone' => $this->department,
                'limit'          => $this->limit,
                'campaign_id'    => $this->campaignId,
                'workspace_id'   => $this->workspaceId,
            ],
            targetUrl: null,
        );
        return [];
    }
}
