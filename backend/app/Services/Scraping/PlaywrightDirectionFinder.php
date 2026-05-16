<?php

namespace App\Services\Scraping;

use App\Contracts\DirectionFinder;
use App\Data\Scraping\DirectionFinderResult;

class PlaywrightDirectionFinder implements DirectionFinder
{
    public function findCLevel(string $companyDomain, string $companyName): DirectionFinderResult
    {
        throw new \LogicException('PlaywrightDirectionFinder implemented in Sprint 7.');
    }
}
