<?php

namespace App\Services\Insee\Mocks;

use App\Contracts\InseeClient;
use App\Data\Sources\InseeCompanyData;

class MockInseeClient implements InseeClient
{
    public function fetchBySiren(string $siren): ?InseeCompanyData
    {
        $path = base_path("tests/fixtures/insee/siren_{$siren}.json");
        if (! file_exists($path)) {
            return new InseeCompanyData($siren, denomination: 'Mock Company ' . $siren, naf: '6201Z', legalForm: 'SAS', effectifRange: '11');
        }
        $raw = json_decode(file_get_contents($path), true) ?: [];
        return new InseeCompanyData(
            siren: $siren,
            denomination: $raw['denomination']  ?? null,
            naf: $raw['naf']                    ?? null,
            legalForm: $raw['legal_form']       ?? null,
            effectifRange: $raw['effectif']     ?? null,
            address: $raw['address']            ?? null,
            postcode: $raw['postcode']          ?? null,
            city: $raw['city']                  ?? null,
            insee: $raw['insee']                ?? null,
            createdAt: $raw['created_at']       ?? null,
            raw: $raw,
        );
    }

    public function searchByCriteria(array $criteria): array
    {
        return [];
    }

    public function iterateByCriteria(array $criteria): \Generator
    {
        yield from $this->searchByCriteria($criteria);
    }
}
