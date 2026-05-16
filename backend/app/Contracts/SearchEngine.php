<?php

namespace App\Contracts;

use App\Data\Scraping\SerpResultData;

interface SearchEngine
{
    /** @return list<SerpResultData> */
    public function search(string $query, int $limit = 10): array;

    public function name(): string;
}
