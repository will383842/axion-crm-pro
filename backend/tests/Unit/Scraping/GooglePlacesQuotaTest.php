<?php

use App\Services\Scraping\GooglePlacesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('skips API call when monthly quota is exceeded', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Config::set('services.google.places.monthly_quota_limit', 100);

    // Simule 100 appels déjà faits ce mois
    $month = now()->format('Y-m');
    Cache::put("gplaces:quota:{$month}", 100, now()->addDays(35));

    Http::fake();  // si appel HTTP est fait, on le saura

    $client = new GooglePlacesClient();
    $reason = null;
    $result = $client->searchText('Boulangerie Dupont Paris', 'FR', $reason);

    expect($result)->toBeNull()
        ->and($reason)->toBe('quota_exceeded');

    Http::assertNothingSent();  // garantit que l'API n'a pas été appelée
});

it('allows API call when under monthly quota', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Config::set('services.google.places.monthly_quota_limit', 100);
    // 99 / 100 → encore 1 dispo
    Cache::put('gplaces:quota:' . now()->format('Y-m'), 99, now()->addDays(35));

    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [['id' => 'X', 'displayName' => ['text' => 'Acme']]],
        ], 200),
    ]);

    $client = new GooglePlacesClient();
    $reason = null;
    $result = $client->searchText('Acme Paris', 'FR', $reason);

    expect($result)->not->toBeNull()
        ->and($reason)->toBeNull();
});

it('increments monthly usage counter after successful call', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Config::set('services.google.places.monthly_quota_limit', 1000);

    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [['id' => 'X']],
        ], 200),
    ]);

    $client = new GooglePlacesClient();
    expect($client->currentMonthUsage())->toBe(0);

    $client->searchText('A query Paris');
    expect($client->currentMonthUsage())->toBe(1);

    $client->searchText('Another query Lyon');
    expect($client->currentMonthUsage())->toBe(2);
});

it('isQuotaExceeded reflects current_month_usage vs limit', function () {
    Config::set('services.google.places.monthly_quota_limit', 50);
    $client = new GooglePlacesClient();

    Cache::put('gplaces:quota:' . now()->format('Y-m'), 49, now()->addDays(35));
    expect($client->isQuotaExceeded())->toBeFalse();

    Cache::put('gplaces:quota:' . now()->format('Y-m'), 50, now()->addDays(35));
    expect($client->isQuotaExceeded())->toBeTrue();

    Cache::put('gplaces:quota:' . now()->format('Y-m'), 51, now()->addDays(35));
    expect($client->isQuotaExceeded())->toBeTrue();
});

it('does not increment quota when call uses cache hit', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Config::set('services.google.places.monthly_quota_limit', 1000);

    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [['id' => 'X', 'displayName' => ['text' => 'Cached']]],
        ], 200),
    ]);

    $client = new GooglePlacesClient();
    $client->searchText('Cached query');
    expect($client->currentMonthUsage())->toBe(1);

    // Le 2e appel devrait hit le cache → pas d'incrément
    $client->searchText('Cached query');
    expect($client->currentMonthUsage())->toBe(1);

    Http::assertSentCount(1);
});

it('quota counter is per-month (key includes YYYY-MM)', function () {
    $client = new GooglePlacesClient();
    $expectedKey = 'gplaces:quota:' . now()->format('Y-m');

    // Simule l'increment
    Cache::put($expectedKey, 42, now()->addDays(35));
    expect($client->currentMonthUsage())->toBe(42);

    // Key d'un autre mois ne doit pas affecter l'usage actuel
    Cache::put('gplaces:quota:2099-12', 999, now()->addDays(35));
    expect($client->currentMonthUsage())->toBe(42);
});
