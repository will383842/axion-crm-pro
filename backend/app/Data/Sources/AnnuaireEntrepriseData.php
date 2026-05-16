<?php

namespace App\Data\Sources;

use Spatie\LaravelData\Data;

class AnnuaireEntrepriseData extends Data
{
    /**
     * @param  list<array{role:string,first_name:?string,last_name:string,birth_date:?string}>  $representatives
     * @param  array<string,mixed>  $raw
     */
    public function __construct(
        public string $siren,
        public ?string $denomination = null,
        public ?string $naf = null,
        public array $representatives = [],
        public ?float $chiffreAffaires = null,
        public ?int $resultatNet = null,
        public ?string $bilansLastYear = null,
        public array $raw = [],
    ) {}
}
