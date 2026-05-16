<?php

namespace App\Services\Scraping;

use App\Contracts\PagesJaunesScraper;
use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

class PlaywrightPagesJaunesScraper implements PagesJaunesScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData
    {
        throw new \LogicException('PlaywrightPagesJaunesScraper implemented in Sprint 6.');
    }
}
