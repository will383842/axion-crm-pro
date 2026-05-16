<?php

namespace App\Services\FranceTravail;

use App\Contracts\FranceTravailClient;

class HttpFranceTravailClient implements FranceTravailClient
{
    public function fetchOffersBySiren(string $siren): array
    {
        throw new \LogicException('HttpFranceTravailClient implemented in Sprint 7.');
    }
}
