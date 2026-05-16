<?php

namespace App\Services\Scraping\Mocks;

use App\Contracts\WebsiteScraper;
use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

class MockWebsiteScraper implements WebsiteScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData
    {
        return new ScrapeResultData(
            runId: $request->runId,
            status: 'success',
            payload: ['source' => 'mock-website'],
            latencyMs: 5,
            fetchedAt: now()->toIso8601String(),
        );
    }
}
