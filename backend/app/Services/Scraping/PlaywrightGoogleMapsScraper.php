<?php

namespace App\Services\Scraping;

use App\Contracts\GoogleMapsScraper;
use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

class PlaywrightGoogleMapsScraper implements GoogleMapsScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData
    {
        throw new \LogicException('PlaywrightGoogleMapsScraper implemented in Sprint 6 (côté Node workers).');
    }
}
