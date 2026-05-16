<?php

namespace App\Services\Scraping\Mocks;

use App\Contracts\DirectionFinder;
use App\Data\Scraping\DirectionFinderResult;

class MockDirectionFinder implements DirectionFinder
{
    public function findCLevel(string $companyDomain, string $companyName): DirectionFinderResult
    {
        return new DirectionFinderResult(
            companyDomain: $companyDomain,
            cLevel: [
                ['name' => 'Mock CEO', 'title' => 'CEO', 'linkedin_url' => null, 'sources' => ['mock'], 'confidence' => 80],
            ],
            meta: ['mock' => true],
        );
    }
}
