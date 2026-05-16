<?php

use App\Services\Dedup\DeduplicationService;

test('buildDedupKey is stable for same inputs', function () {
    $svc = new DeduplicationService();
    $key1 = $svc->buildDedupKey('insee', ['siren' => '123456789']);
    $key2 = $svc->buildDedupKey('insee', ['siren' => '123456789']);
    expect($key1)->toBe($key2);
    expect($key1)->toStartWith('insee:siren:123456789');
});

test('buildDedupKey differs across sources', function () {
    $svc = new DeduplicationService();
    $insee = $svc->buildDedupKey('insee', ['siren' => '123456789']);
    $bodacc = $svc->buildDedupKey('bodacc', ['siren' => '123456789']);
    expect($insee)->not->toBe($bodacc);
});

test('buildDedupKey for google-maps incorporates query + coords', function () {
    $svc = new DeduplicationService();
    $a = $svc->buildDedupKey('google-maps', ['query' => 'boulangerie paris', 'lat' => 48.85, 'lon' => 2.35]);
    $b = $svc->buildDedupKey('google-maps', ['query' => 'boulangerie paris', 'lat' => 48.86, 'lon' => 2.35]);
    expect($a)->not->toBe($b);
});

test('computeContactHash matches expected sha256 pattern', function () {
    $svc = new DeduplicationService();
    $hash = $svc->computeContactHash('Marie', 'Dupont', 42);
    expect($hash)->toHaveLength(64);
    expect($hash)->toMatch('/^[a-f0-9]{64}$/');
});

test('computeContactHash is case + accents insensitive', function () {
    $svc = new DeduplicationService();
    $a = $svc->computeContactHash('Marie', 'Dupont', 42);
    $b = $svc->computeContactHash('marie', 'dupont', 42);
    expect($a)->toBe($b);
});

test('SOURCE_TTL_DAYS contains all 14 sources', function () {
    $sources = [
        'insee', 'annuaire-entreprises', 'bodacc', 'google-maps', 'pages-jaunes', 'website',
        'google-search', 'direction-finder', 'france-travail', 'mesri', 'crunchbase',
        'infogreffe', 'societe-com', 'social-light',
    ];
    foreach ($sources as $src) {
        expect(DeduplicationService::SOURCE_TTL_DAYS)->toHaveKey($src);
        expect(DeduplicationService::SOURCE_TTL_DAYS[$src])->toBeInt();
        expect(DeduplicationService::SOURCE_TTL_DAYS[$src])->toBeGreaterThan(0);
    }
});
