<?php

namespace App\Services\Scraping\Mocks;

use App\Contracts\GoogleMapsScraper;
use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

class MockGoogleMapsScraper implements GoogleMapsScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData
    {
        return new ScrapeResultData(
            runId: $request->runId,
            status: 'success',
            payload: ['source' => 'mock', 'target' => $request->targetUrl],
            emails: [],
            phones: [],
            latencyMs: 5,
            fetchedAt: now()->toIso8601String(),
        );
    }
}
