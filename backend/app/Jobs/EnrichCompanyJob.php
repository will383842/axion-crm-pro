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

class EnrichCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RunsInWorkspace, SerializesModels;

    public int $tries = 3;

    public int $timeout = 600;

    /**
     * $workspaceId est OPTIONNEL pour rester compatible avec les cinq points de
     * dispatch existants (lot L0 : strictement additif). Quand il est fourni,
     * le job pose le contexte workspace pour toute sa durée — c'est la seule
     * façon correcte de toucher des données scopées hors requête HTTP, le
     * middleware SetCurrentWorkspace ne couvrant que le HTTP.
     */
    public function __construct(
        public readonly int $companyId,
        public readonly ?string $workspaceId = null,
    ) {}

    /** @return list<int> Exponential backoff (60s, 5min, 30min) entre retries. */
    public function backoff(): array
    {
        return [60, 300, 1800];
    }

    public function handle(WaterfallOrchestrator $waterfall): void
    {
        if ($this->workspaceId === null) {
            $this->enrich($waterfall);

            return;
        }

        $this->inWorkspace($this->workspaceId, fn () => $this->enrich($waterfall));
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
