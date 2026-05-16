<?php

namespace App\Data\Scraping;

use Spatie\LaravelData\Data;

class DirectionFinderResult extends Data
{
    /**
     * @param  list<array{name:string,title:string,linkedin_url:?string,sources:list<string>,confidence:int}>  $cLevel
     * @param  array<string,mixed>  $meta
     */
    public function __construct(
        public string $companyDomain,
        public array $cLevel = [],
        public array $meta = [],
        public string $status = 'success',
    ) {}
}
