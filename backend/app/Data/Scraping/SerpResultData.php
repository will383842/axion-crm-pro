<?php

namespace App\Data\Scraping;

use Spatie\LaravelData\Data;

class SerpResultData extends Data
{
    public function __construct(
        public int $rank,
        public string $title,
        public string $url,
        public ?string $snippet = null,
        public ?string $engine = null,
    ) {}
}
