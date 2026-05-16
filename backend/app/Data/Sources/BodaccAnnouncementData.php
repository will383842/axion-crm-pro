<?php

namespace App\Data\Sources;

use Spatie\LaravelData\Data;

class BodaccAnnouncementData extends Data
{
    public function __construct(
        public string $siren,
        public string $type,            // creation | modification | radiation | procedure
        public string $publishedAt,
        public ?string $tribunal = null,
        public ?string $reference = null,
        public ?string $rawText = null,
    ) {}
}
