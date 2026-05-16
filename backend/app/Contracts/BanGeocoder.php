<?php

namespace App\Contracts;

use App\Data\Sources\GeocodeResult;

interface BanGeocoder
{
    public function geocode(string $address, ?string $postcode = null): ?GeocodeResult;
}
