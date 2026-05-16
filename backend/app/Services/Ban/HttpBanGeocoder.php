<?php

namespace App\Services\Ban;

use App\Contracts\BanGeocoder;
use App\Data\Sources\GeocodeResult;

class HttpBanGeocoder implements BanGeocoder
{
    public function geocode(string $address, ?string $postcode = null): ?GeocodeResult
    {
        throw new \LogicException('HttpBanGeocoder implemented in Sprint 7.');
    }
}
