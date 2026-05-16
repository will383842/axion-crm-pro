<?php

namespace App\Services\Scraping;

use App\Contracts\SearchEngine;

class PlaywrightSearchEngine implements SearchEngine
{
    public function search(string $query, int $limit = 10): array
    {
        throw new \LogicException('PlaywrightSearchEngine implemented in Sprint 7.');
    }

    public function name(): string
    {
        return 'playwright-google';
    }
}
