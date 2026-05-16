<?php

namespace App\Jobs;

use App\Models\Company;
use App\Services\Waterfall\WaterfallOrchestrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class EnrichCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;
    public int $timeout = 600;

    public function __construct(public readonly int $companyId) {}

    public function handle(WaterfallOrchestrator $waterfall): void
    {
        $company = Company::find($this->companyId);
        if (! $company) {
            return;
        }
        $waterfall->enrich($company);
    }
}
