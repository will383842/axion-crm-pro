<?php

namespace App\Contracts;

use App\Data\Scraping\ScrapeRequestData;
use App\Data\Scraping\ScrapeResultData;

interface GoogleMapsScraper
{
    public function scrape(ScrapeRequestData $request): ScrapeResultData;
}
