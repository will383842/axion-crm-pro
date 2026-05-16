<?php

namespace App\Services\Scraping\Mocks;

use App\Contracts\SearchEngine;
use App\Data\Scraping\SerpResultData;

class MockSearchEngine implements SearchEngine
{
    public function search(string $query, int $limit = 10): array
    {
        return [
            new SerpResultData(rank: 1, title: "Mock result for $query", url: 'https://www.linkedin.com/company/mock', snippet: 'mock', engine: 'mock-google'),
        ];
    }

    public function name(): string
    {
        return 'mock';
    }
}
