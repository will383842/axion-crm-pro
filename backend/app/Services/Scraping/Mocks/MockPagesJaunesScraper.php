<?php

namespace App\Services\Scraping\Mocks;

use App\Contracts\PagesJaunesScraper;
use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

class MockPagesJaunesScraper implements PagesJaunesScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData
    {
        return new ScrapeResultData(
            runId: $request->runId,
            status: 'success',
            payload: ['source' => 'mock-pj'],
            latencyMs: 5,
            fetchedAt: now()->toIso8601String(),
        );
    }
}
