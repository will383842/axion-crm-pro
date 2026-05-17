<?php

use App\Services\FranceTravail\FranceTravailDiscoveryClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('returns empty when client credentials missing', function () {
    $client = new FranceTravailDiscoveryClient(clientId: '', clientSecret: '');
    expect($client->searchEntreprisesByDept('75', 10))->toBe([]);
});

it('returns deduplicated entreprises from offres', function () {
    Http::fake([
        '*oauth2/access_token*' => Http::response(['access_token' => 'fake-token'], 200),
        'api.francetravail.io/*' => Http::response([
            'resultats' => [
                [
                    'id'          => 'OFF1',
                    'entreprise'  => ['siret' => '12345678900012', 'nom' => 'Acme SA', 'activitePrincipale' => '6201Z'],
                    'lieuTravail' => ['libelle' => 'Paris 75001', 'codePostal' => '75001'],
                ],
                // Doublon SIREN
                [
                    'id'          => 'OFF2',
                    'entreprise'  => ['siret' => '12345678900099', 'nom' => 'Acme SA', 'activitePrincipale' => '6201Z'],
                    'lieuTravail' => ['libelle' => 'Paris', 'codePostal' => '75002'],
                ],
                [
                    'id'          => 'OFF3',
                    'entreprise'  => ['siret' => '98765432100015', 'nom' => 'Foo SARL', 'activitePrincipale' => '4321A'],
                    'lieuTravail' => ['libelle' => 'Lyon', 'codePostal' => '69001'],
                ],
            ],
        ], 200),
    ]);

    $client = new FranceTravailDiscoveryClient(clientId: 'id', clientSecret: 'secret');
    $results = $client->searchEntreprisesByDept('75', 100);

    expect($results)->toHaveCount(2);
    expect($results[0]->siren)->toBe('123456789');
    expect($results[0]->denomination)->toBe('Acme SA');
    expect($results[0]->naf)->toBe('6201Z');
    expect($results[0]->raw['discovery_source'])->toBe('france_travail');
});

it('returns empty on 204 No Content', function () {
    Http::fake([
        '*oauth2/access_token*'  => Http::response(['access_token' => 'tok'], 200),
        'api.francetravail.io/*' => Http::response(null, 204),
    ]);
    $client = new FranceTravailDiscoveryClient(clientId: 'id', clientSecret: 'secret');
    expect($client->searchEntreprisesByDept('99', 50))->toBe([]);
});

it('skips offres with invalid SIRET', function () {
    Http::fake([
        '*oauth2/access_token*'  => Http::response(['access_token' => 'tok'], 200),
        'api.francetravail.io/*' => Http::response([
            'resultats' => [
                ['id' => 'OFF1', 'entreprise' => ['nom' => 'NoSiret'], 'lieuTravail' => []],
                ['id' => 'OFF2', 'entreprise' => ['siret' => '123', 'nom' => 'TooShort'], 'lieuTravail' => []],
            ],
        ], 200),
    ]);
    $client = new FranceTravailDiscoveryClient(clientId: 'id', clientSecret: 'secret');
    expect($client->searchEntreprisesByDept('75', 10))->toBe([]);
});

/* ============================================================================
 * Sprint H3 — Filtre INSEE etatAdministratif='A' actif
 * ============================================================================ */

it('Sprint H3: filters out radiées entreprises via INSEE check', function () {
    Http::fake([
        '*oauth2/access_token*' => Http::response(['access_token' => 'tok'], 200),
        'api.francetravail.io/*' => Http::response([
            'resultats' => [
                ['id' => 'A', 'entreprise' => ['siret' => '11111111100012', 'nom' => 'Active'], 'lieuTravail' => []],
                ['id' => 'B', 'entreprise' => ['siret' => '22222222200012', 'nom' => 'Radiee'], 'lieuTravail' => []],
                ['id' => 'C', 'entreprise' => ['siret' => '33333333300012', 'nom' => 'Inconnue'], 'lieuTravail' => []],
            ],
        ], 200),
    ]);

    app()->bind(\App\Contracts\InseeClient::class, fn () => new class implements \App\Contracts\InseeClient {
        public function fetchBySiren(string $siren): ?\App\Data\Sources\InseeCompanyData {
            return match ($siren) {
                '111111111' => new \App\Data\Sources\InseeCompanyData($siren, etatAdministratif: 'A'),
                '222222222' => new \App\Data\Sources\InseeCompanyData($siren, etatAdministratif: 'C'),
                default     => null,
            };
        }
        public function searchByCriteria(array $criteria): array { return []; }
    });

    $client = new FranceTravailDiscoveryClient(clientId: 'id', clientSecret: 'secret');
    $results = $client->searchEntreprisesByDept('75', 100);

    expect($results)->toHaveCount(1);
    expect($results[0]->siren)->toBe('111111111');
});

it('Sprint H3: graceful fallback if InseeClient throws', function () {
    Http::fake([
        '*oauth2/access_token*' => Http::response(['access_token' => 'tok'], 200),
        'api.francetravail.io/*' => Http::response([
            'resultats' => [
                ['id' => 'A', 'entreprise' => ['siret' => '44444444400012', 'nom' => 'Whatever'], 'lieuTravail' => []],
            ],
        ], 200),
    ]);

    app()->bind(\App\Contracts\InseeClient::class, fn () => new class implements \App\Contracts\InseeClient {
        public function fetchBySiren(string $siren): ?\App\Data\Sources\InseeCompanyData {
            throw new \RuntimeException('INSEE down');
        }
        public function searchByCriteria(array $criteria): array { return []; }
    });

    $client = new FranceTravailDiscoveryClient(clientId: 'id', clientSecret: 'secret');
    $results = $client->searchEntreprisesByDept('75', 100);

    expect($results)->toHaveCount(1);
});
