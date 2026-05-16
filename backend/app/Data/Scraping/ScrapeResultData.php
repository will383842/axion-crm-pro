<?php

namespace App\Data\Scraping;

use Spatie\LaravelData\Data;

class ScrapeResultData extends Data
{
    /**
     * @param  array<string,mixed>  $payload
     * @param  list<string>  $emails
     * @param  list<string>  $phones
     */
    public function __construct(
        public string $runId,
        public string $status,          // success | failed | partial
        public array $payload = [],
        public array $emails = [],
        public array $phones = [],
        public ?string $rawHtmlPath = null,
        public ?string $error = null,
        public int $latencyMs = 0,
        public ?string $fetchedAt = null,
    ) {}
}
