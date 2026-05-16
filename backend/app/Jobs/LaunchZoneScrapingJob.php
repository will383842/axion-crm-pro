<?php

namespace App\Jobs;

use App\Contracts\InseeClient;
use App\Models\Company;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Lance le scraping d'une zone géo+secteur+taille :
 * 1. Recherche INSEE selon critères → liste SIREN
 * 2. Crée companies (upsert) + dispatch EnrichCompanyJob pour chacune
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
    ) {}

    public function handle(InseeClient $insee): void
    {
        $results = $insee->searchByCriteria([
            'department' => $this->department,
            'naf'        => $this->naf,
            'limit'      => $this->limit,
        ]);

        foreach ($results as $data) {
            $company = Company::query()->updateOrCreate(
                ['workspace_id' => $this->workspaceId, 'siren' => $data->siren],
                [
                    'denomination'    => $data->denomination,
                    'naf'             => $data->naf ?? $this->naf,
                    'legal_form'      => $data->legalForm,
                    'effectif_range'  => $data->effectifRange,
                    'size_category'   => $this->sizeCategory,
                    'discovery_source'=> 'coverage_launch',
                ],
            );
            EnrichCompanyJob::dispatch($company->id);
        }
    }
}
