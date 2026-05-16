<?php

namespace App\Data\Sources;

use Spatie\LaravelData\Data;

class InseeCompanyData extends Data
{
    /** @param  array<string,mixed>  $raw */
    public function __construct(
        public string $siren,
        public ?string $denomination = null,
        public ?string $naf = null,
        public ?string $legalForm = null,
        public ?string $effectifRange = null,
        public ?string $address = null,
        public ?string $postcode = null,
        public ?string $city = null,
        public ?string $insee = null,
        public ?string $createdAt = null,
        public array $raw = [],
    ) {}
}
