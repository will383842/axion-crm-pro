<?php

namespace App\Data\Scraping;

use Spatie\LaravelData\Data;

class ScrapeRequestData extends Data
{
    /** @param  array<string,mixed>  $context */
    public function __construct(
        public string $runId,
        public string $source,          // google-maps | pages-jaunes | website | google-search | direction-finder
        public string $targetUrl,
        public array $context = [],
        public ?int $companyId = null,
        public ?string $proxyUrl = null,
        public ?string $userAgent = null,
        public int $timeoutS = 60,
    ) {}
}
