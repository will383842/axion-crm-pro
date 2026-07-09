<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function tagStatusWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'tg-' . Str::random(6),
        'name' => 'WS Tag Status',
    ]);
}

function insertEmission(string $ws, string $name, string $qid): int
{
    return DB::table('media')->insertGetId([
        'workspace_id' => $ws,
        'name' => $name,
        'media_type' => 'tv_emission',
        'socials' => json_encode(['wikidata_id' => $qid]),
        'source' => 'wikidata',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Réponse SPARQL fake : SEULE l'émission Q1001 porte une P582 (date de fin passée).
 * Q1002 n'apparaît pas → toujours en cours.
 */
function fakeSparqlEndDates(): array
{
    return [
        'head' => ['vars' => ['prog', 'end']],
        'results' => [
            'bindings' => [
                [
                    'prog' => ['type' => 'uri', 'value' => 'http://www.wikidata.org/entity/Q1001'],
                    'end' => ['type' => 'literal', 'value' => '1998-08-31T00:00:00Z'],
                ],
            ],
        ],
    ];
}

it('marque terminée l\'émission avec P582 et actuelle celle sans', function () {
    Http::fake(['query.wikidata.org/*' => Http::response(fakeSparqlEndDates(), 200)]);
    $ws = tagStatusWorkspace();

    $ended = insertEmission($ws->id, 'Le Grand Journal', 'Q1001');   // a une date de fin passée
    $current = insertEmission($ws->id, 'Quotidien', 'Q1002');        // pas de P582

    $this->artisan('media:tag-emissions-status', ['--workspace' => $ws->id])->assertExitCode(0);

    $endedSocials = json_decode(DB::table('media')->where('id', $ended)->value('socials'), true);
    $currentSocials = json_decode(DB::table('media')->where('id', $current)->value('socials'), true);

    // Terminée : ended_at posé, wikidata_id préservé.
    expect($endedSocials['ended_at'])->toBe('1998-08-31')
        ->and($endedSocials['wikidata_id'])->toBe('Q1001')
        ->and($endedSocials)->toHaveKey('wikidata_status_checked_at');

    // Actuelle : PAS de ended_at, wikidata_id préservé, contrôle horodaté.
    expect($currentSocials)->not->toHaveKey('ended_at')
        ->and($currentSocials['wikidata_id'])->toBe('Q1002')
        ->and($currentSocials)->toHaveKey('wikidata_status_checked_at');
});

it('parse correctement une réponse SANS aucun P582 (tout actuel)', function () {
    Http::fake(['query.wikidata.org/*' => Http::response([
        'head' => ['vars' => ['prog', 'end']],
        'results' => ['bindings' => []],
    ], 200)]);
    $ws = tagStatusWorkspace();

    $id = insertEmission($ws->id, 'Émission Vivante', 'Q2001');

    $this->artisan('media:tag-emissions-status', ['--workspace' => $ws->id])->assertExitCode(0);

    $socials = json_decode(DB::table('media')->where('id', $id)->value('socials'), true);
    expect($socials)->not->toHaveKey('ended_at')
        ->and($socials)->toHaveKey('wikidata_status_checked_at');
});

it('--dry-run ne persiste rien', function () {
    Http::fake(['query.wikidata.org/*' => Http::response(fakeSparqlEndDates(), 200)]);
    $ws = tagStatusWorkspace();

    $id = insertEmission($ws->id, 'Le Grand Journal', 'Q1001');

    $this->artisan('media:tag-emissions-status', ['--dry-run' => true, '--workspace' => $ws->id])->assertExitCode(0);

    $socials = json_decode(DB::table('media')->where('id', $id)->value('socials'), true);
    expect($socials)->not->toHaveKey('ended_at')
        ->and($socials)->not->toHaveKey('wikidata_status_checked_at');
});
