<?php

use App\Services\Rotations\SearchEngineRotator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function seedEngine(string $slug, string $name, int $weight, bool $enabled = true): void
{
    DB::table('search_engines')->updateOrInsert(
        ['slug' => $slug],
        [
            'name'     => $name,
            'base_url' => "https://{$slug}.example",
            'weight'   => $weight,
            'enabled'  => $enabled,
        ],
    );
}

test('SearchEngineRotator retourne null si aucun moteur enabled', function () {
    DB::table('search_engines')->update(['enabled' => false]);
    $rotator = new SearchEngineRotator();
    expect($rotator->pick())->toBeNull();
});

test('SearchEngineRotator retourne un engine si dispo', function () {
    DB::table('search_engines')->update(['enabled' => false]);
    seedEngine('test1', 'Test Engine', 10);

    $rotator = new SearchEngineRotator();
    $picked = $rotator->pick();
    expect($picked)->not->toBeNull();
    expect($picked['slug'])->toBe('test1');
});

test('SearchEngineRotator pondère par weight statistiquement', function () {
    DB::table('search_engines')->update(['enabled' => false]);
    seedEngine('high', 'High', 90);
    seedEngine('low',  'Low',  10);

    $rotator = new SearchEngineRotator();
    $counts = ['high' => 0, 'low' => 0];
    for ($i = 0; $i < 200; $i++) {
        $picked = $rotator->pick();
        $counts[$picked['slug']]++;
    }
    expect($counts['high'])->toBeGreaterThan($counts['low']);
});

test('SearchEngineRotator retourne null si total weight = 0', function () {
    DB::table('search_engines')->update(['enabled' => false]);
    seedEngine('zero', 'Zero', 0);

    $rotator = new SearchEngineRotator();
    expect($rotator->pick())->toBeNull();
});
