<?php

namespace App\Data\Sources;

use Spatie\LaravelData\Data;

class GeocodeResult extends Data
{
    public function __construct(
        public string $address,
        public float $lat,
        public float $lon,
        public ?string $insee = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public float $confidence = 0.0,
        public ?string $rawProvider = null,
    ) {}
}
