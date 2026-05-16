<?php

namespace App\Services\FranceTravail\Mocks;

use App\Contracts\FranceTravailClient;

class MockFranceTravailClient implements FranceTravailClient
{
    public function fetchOffersBySiren(string $siren): array
    {
        return [];
    }
}
