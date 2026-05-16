<?php

namespace App\Contracts;

use App\Data\Sources\JobOfferData;

interface FranceTravailClient
{
    /** @return list<JobOfferData> */
    public function fetchOffersBySiren(string $siren): array;
}
