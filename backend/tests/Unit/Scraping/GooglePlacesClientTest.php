<?php

use App\Services\Scraping\GooglePlacesClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
});

it('returns null when API key is missing', function () {
    Config::set('services.google.places.api_key', null);
    $client = new GooglePlacesClient();
    expect($client->searchText('Boulangerie Dupont Paris'))->toBeNull();
});

it('returns first place when API responds with results', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [
                [
                    'id'                          => 'ChIJfake123',
                    'displayName'                 => ['text' => 'Boulangerie Dupont'],
                    'formattedAddress'            => '12 rue de Paris, 75001 Paris',
                    'location'                    => ['latitude' => 48.8566, 'longitude' => 2.3522],
                    'businessStatus'              => 'OPERATIONAL',
                    'types'                       => ['bakery', 'food'],
                    'primaryType'                 => 'bakery',
                    'internationalPhoneNumber'    => '+33 1 23 45 67 89',
                    'websiteUri'                  => 'https://boulangerie-dupont.fr/',
                    'rating'                      => 4.6,
                    'userRatingCount'             => 142,
                ],
            ],
        ], 200),
    ]);

    $client = new GooglePlacesClient();
    $place = $client->searchText('Boulangerie Dupont Paris');

    expect($place)->not->toBeNull()
        ->and($place['displayName']['text'])->toBe('Boulangerie Dupont')
        ->and($place['websiteUri'])->toBe('https://boulangerie-dupont.fr/');
});

it('caches result for 30 days to avoid quota waste', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Http::fake([
        'places.googleapis.com/*' => Http::response([
            'places' => [['id' => 'ChIJfake', 'displayName' => ['text' => 'Acme']]],
        ], 200),
    ]);
    $client = new GooglePlacesClient();
    $first  = $client->searchText('Acme Lyon');
    $second = $client->searchText('Acme Lyon');
    expect($first)->toBe($second);
    Http::assertSentCount(1);
});

it('returns null on HTTP error', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Http::fake([
        'places.googleapis.com/*' => Http::response([], 503),
    ]);
    $client = new GooglePlacesClient();
    expect($client->searchText('Acme'))->toBeNull();
});

it('returns null on empty places array', function () {
    Config::set('services.google.places.api_key', 'fake-key');
    Http::fake([
        'places.googleapis.com/*' => Http::response(['places' => []], 200),
    ]);
    $client = new GooglePlacesClient();
    expect($client->searchText('Nothing Found Co Mars'))->toBeNull();
});

it('flatten returns null-safe payload when place is null', function () {
    $client = new GooglePlacesClient();
    $flat = $client->flatten(null);
    expect($flat)->toHaveKeys([
        'phone', 'website', 'address', 'lat', 'lon', 'rating',
        'user_rating_count', 'business_status', 'primary_type',
        'types', 'opening_hours', 'google_place_id', 'display_name',
    ])
        ->and($flat['phone'])->toBeNull()
        ->and($flat['types'])->toBe([])
        ->and($flat['opening_hours'])->toBe([]);
});

it('flatten extracts phone with fallback international → national', function () {
    $client = new GooglePlacesClient();
    $flat1 = $client->flatten(['internationalPhoneNumber' => '+33 1 23 45 67 89']);
    expect($flat1['phone'])->toBe('+33 1 23 45 67 89');

    $flat2 = $client->flatten(['nationalPhoneNumber' => '01 23 45 67 89']);
    expect($flat2['phone'])->toBe('01 23 45 67 89');
});

it('flatten extracts opening hours weekday descriptions', function () {
    $client = new GooglePlacesClient();
    $flat = $client->flatten([
        'regularOpeningHours' => [
            'weekdayDescriptions' => [
                'lundi: 09:00–12:00, 14:00–19:00',
                'mardi: 09:00–12:00, 14:00–19:00',
            ],
        ],
    ]);
    expect($flat['opening_hours'])->toHaveCount(2);
});
