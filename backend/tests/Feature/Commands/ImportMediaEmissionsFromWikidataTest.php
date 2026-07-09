<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeWikidataWorkspace(): Workspace
{
    return Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'wd-' . Str::random(6),
        'name' => 'WS Wikidata',
    ]);
}

/**
 * Construit une réponse SPARQL fake (SELECT results JSON de Wikidata) :
 * 2 émissions TV, chaîne partagée « Canal+ », 1 présentateur + 1 producteur.
 */
function fakeSparqlEmissions(): array
{
    $lit = fn (string $v, string $lang = 'fr') => ['xml:lang' => $lang, 'type' => 'literal', 'value' => $v];
    $uri = fn (string $q) => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/' . $q];

    return [
        'head'    => ['vars' => ['prog', 'progLabel', 'genreLabel', 'broadcasterLabel', 'person', 'personLabel', 'role']],
        'results' => [
            'bindings' => [
                // Émission 1 — présentateur
                [
                    'prog'             => $uri('Q1001'),
                    'progLabel'        => $lit('Le Grand Journal'),
                    'genreLabel'       => $lit('talk-show'),
                    'broadcasterLabel' => $lit('Canal+'),
                    'person'           => $uri('Q2001'),
                    'personLabel'      => $lit('Michel Denisot'),
                    'role'             => $lit('présentateur'),
                ],
                // Émission 1 — producteur (2e ligne, même émission)
                [
                    'prog'             => $uri('Q1001'),
                    'progLabel'        => $lit('Le Grand Journal'),
                    'genreLabel'       => $lit('talk-show'),
                    'broadcasterLabel' => $lit('Canal+'),
                    'person'           => $uri('Q2002'),
                    'personLabel'      => $lit('Renaud Le Van Kim'),
                    'role'             => $lit('producteur'),
                ],
                // Émission 2 — même chaîne, sans personne
                [
                    'prog'             => $uri('Q1002'),
                    'progLabel'        => $lit('Les Guignols de l\'info'),
                    'broadcasterLabel' => $lit('Canal+'),
                ],
            ],
        ],
    ];
}

it('parse une réponse SPARQL fake et insère émissions + présentateurs', function () {
    Http::fake([
        'query.wikidata.org/*' => Http::response(fakeSparqlEmissions(), 200),
    ]);

    $ws = makeWikidataWorkspace();

    // --limit=2 : le nombre d'émissions distinctes de la fixture → 1 seul appel HTTP,
    // pas de seconde page ni de passe radio.
    $this->artisan('media:import-emissions-wikidata', ['--limit' => 2, '--workspace' => $ws->id])
        ->assertExitCode(0);

    // 2 émissions (tv_emission) + 1 chaîne (tv) partagée.
    expect(DB::table('media')->where('media_type', 'tv_emission')->count())->toBe(2)
        ->and(DB::table('media')->where('media_type', 'tv')->where('name', 'Canal+')->count())->toBe(1);

    $emission = DB::table('media')->where('media_type', 'tv_emission')->where('name', 'Le Grand Journal')->first();
    expect($emission->editorial_theme)->toBe('talk-show')
        ->and(json_decode($emission->socials, true)['wikidata_id'])->toBe('Q1001')
        ->and($emission->source)->toBe('wikidata');

    // Émission rattachée à la chaîne Canal+.
    $channel = DB::table('media')->where('media_type', 'tv')->where('name', 'Canal+')->first();
    expect((int) $emission->parent_media_id)->toBe((int) $channel->id);

    // 2 personnes : présentateur + producteur.
    expect(DB::table('journalists')->count())->toBe(2)
        ->and(DB::table('journalists')->where('last_name', 'Denisot')->where('first_name', 'Michel')->where('role', 'présentateur')->where('source', 'wikidata')->count())->toBe(1)
        ->and(DB::table('journalists')->where('last_name', 'Kim')->where('first_name', 'Renaud Le Van')->where('role', 'producteur')->count())->toBe(1);
});

it('est idempotent : un 2e run n\'ajoute aucun doublon', function () {
    Http::fake([
        'query.wikidata.org/*' => Http::response(fakeSparqlEmissions(), 200),
    ]);
    $ws = makeWikidataWorkspace();

    $this->artisan('media:import-emissions-wikidata', ['--limit' => 2, '--workspace' => $ws->id])->assertExitCode(0);
    $this->artisan('media:import-emissions-wikidata', ['--limit' => 2, '--workspace' => $ws->id])->assertExitCode(0);

    expect(DB::table('media')->where('media_type', 'tv_emission')->count())->toBe(2)
        ->and(DB::table('media')->where('media_type', 'tv')->count())->toBe(1)
        ->and(DB::table('journalists')->count())->toBe(2);
});

it('--dry-run ne persiste rien', function () {
    Http::fake([
        'query.wikidata.org/*' => Http::response(fakeSparqlEmissions(), 200),
    ]);
    $ws = makeWikidataWorkspace();

    $this->artisan('media:import-emissions-wikidata', ['--limit' => 2, '--dry-run' => true, '--workspace' => $ws->id])
        ->assertExitCode(0);

    expect(DB::table('media')->count())->toBe(0)
        ->and(DB::table('journalists')->count())->toBe(0);
});
