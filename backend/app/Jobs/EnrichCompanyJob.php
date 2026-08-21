<?php

namespace App\Jobs;

use App\Jobs\Concerns\RunsInWorkspace;
use App\Models\Company;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class EnrichCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * 🔴 CONSTAT B11-002 / B17-010. Ce constructeur portait, depuis le lot L0,
     * un second paramètre PROMU `?string $workspaceId = null`. Mesure du
     * 2026-08-21 :
     *
     *   grep -rn "EnrichCompanyJob::dispatch" app/  ->  4 sites, tous à UN argument
     *
     * Il valait donc `null` à tous les coups, et `handle()` partait par la
     * branche « pas de contexte ». Un paramètre promu est en outre le mauvais
     * porteur : `unserialize()` d'une charge écrite avant le lot le laisse NON
     * INITIALISÉ (mesuré, cf. `RunsInWorkspace`). L'espace passe désormais par
     * `->pourEspace()`, propriété déclarée du trait.
     */
    public function __construct(
        public readonly int $companyId,
    ) {}

    /** @return list<int> Exponential backoff (60s, 5min, 30min) entre retries. */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(WaterfallOrchestrator $waterfall): void
    {
        $espace = $this->espaceDuJob() ?? $this->espaceDepuisLaLigne('companies', $this->companyId);

        if ($espace === null) {
            // Ni la charge ni la ligne pivot ne nomment d'espace : sous RLS
            // armée, la lecture d'amorçage rend `null` elle aussi. On ne
            // travaille PAS à l'aveugle, et on ne se tait pas.
            Log::warning('EnrichCompanyJob: aucun espace de travail — enrichissement abandonne (constat B11-002)', [
                'company_id' => $this->companyId,
            ]);

            return;
        }

        $this->inWorkspace($espace, fn () => $this->enrich($waterfall));
    }

    private function enrich(WaterfallOrchestrator $waterfall): void
    {
        $company = Company::find($this->companyId);
        if (! $company) {
            return;
        }
        $waterfall->enrich($company);
    }
}
