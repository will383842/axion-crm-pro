<?php

namespace App\Services\Ban;

use App\Contracts\BanGeocoder;
use App\Data\Sources\GeocodeResult;
use App\Services\Http\SsrfGuard;
use Illuminate\Support\Facades\Http;

class HttpBanGeocoder implements BanGeocoder
{
    private const BASE_URL = 'https://api-adresse.data.gouv.fr';

    public function geocode(string $address, ?string $postcode = null): ?GeocodeResult
    {
        SsrfGuard::ensure(self::BASE_URL);
        $resp = Http::timeout(10)
            ->get(self::BASE_URL . '/search/', [
                'q' => $address, 'postcode' => $postcode, 'limit' => 1, 'autocomplete' => 0,
            ]);

        if ($resp->failed()) {
            return null;
        }
        $features = $resp->json('features', []);
        if (empty($features)) {
            return null;
        }
        $f = $features[0];
        $props = $f['properties'] ?? [];
        $coords = $f['geometry']['coordinates'] ?? [];

        return new GeocodeResult(
            address: (string) ($props['label'] ?? $address),
            lat: (float) ($coords[1] ?? 0),
            lon: (float) ($coords[0] ?? 0),
            insee: $props['citycode'] ?? null,
            postcode: $props['postcode'] ?? $postcode,
            city: $props['city'] ?? null,
            confidence: (float) ($props['score'] ?? 0),
            rawProvider: 'ban',
        );
    }
}
