<?php

namespace App\Services\Ban\Mocks;

use App\Contracts\BanGeocoder;
use App\Data\Sources\GeocodeResult;

class MockBanGeocoder implements BanGeocoder
{
    public function geocode(string $address, ?string $postcode = null): ?GeocodeResult
    {
        return new GeocodeResult(
            address: $address,
            lat: 48.8566,
            lon: 2.3522,
            insee: '75056',
            postcode: $postcode ?? '75001',
            city: 'Paris',
            confidence: 0.85,
            rawProvider: 'mock',
        );
    }
}
