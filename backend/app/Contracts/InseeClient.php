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

    /**
     * Itère (générateur) toutes les entreprises correspondant aux critères, en
     * paginant — pour récupérer un département entier sans tout charger en mémoire.
     *
     * @param  array<string,mixed>  $criteria
     * @return \Generator<int, InseeCompanyData>
     */
    public function iterateByCriteria(array $criteria): \Generator;
}
