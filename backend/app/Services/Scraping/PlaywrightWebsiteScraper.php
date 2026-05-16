<?php

namespace App\Services\Scraping;

use App\Contracts\WebsiteScraper;
use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

class PlaywrightWebsiteScraper implements WebsiteScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData
    {
        throw new \LogicException('PlaywrightWebsiteScraper implemented in Sprint 6.');
    }
}
