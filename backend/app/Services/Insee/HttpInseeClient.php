<?php

namespace App\Services\Insee;

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;

class HttpInseeClient implements InseeClient
{
    public function fetchBySiren(string $siren): ?InseeCompanyData
    {
        throw new \LogicException('HttpInseeClient implemented in Sprint 5.');
    }

    public function searchByCriteria(array $criteria): array
    {
        throw new \LogicException('HttpInseeClient implemented in Sprint 5.');
    }
}
