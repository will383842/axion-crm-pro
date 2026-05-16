<?php

namespace App\Contracts;

use App\Data\Sources\InseeCompanyData;

interface InseeClient
{
    public function fetchBySiren(string $siren): ?InseeCompanyData;

    /**
     * @param  array<string,mixed>  $criteria
     * @return list<InseeCompanyData>
     */
    public function searchByCriteria(array $criteria): array;
}
