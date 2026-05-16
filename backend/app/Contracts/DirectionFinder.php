<?php

namespace App\Contracts;

use App\Data\Scraping\DirectionFinderResult;

interface DirectionFinder
{
    public function findCLevel(string $companyDomain, string $companyName): DirectionFinderResult;
}
