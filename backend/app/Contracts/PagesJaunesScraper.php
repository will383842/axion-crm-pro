<?php

namespace App\Contracts;

use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

interface PagesJaunesScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData;
}
