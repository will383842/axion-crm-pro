<?php

namespace App\Data\Sources;

use Spatie\LaravelData\Data;

class JobOfferData extends Data
{
    public function __construct(
        public string $siren,
        public string $title,
        public ?string $publishedAt = null,
        public ?string $city = null,
        public ?string $contract = null,
        public ?string $sourceUrl = null,
    ) {}
}
