<?php

use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function linkWorkspace(): Workspace
{
    return Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'lk-' . Str::random(6),
        'name' => 'WS Link',
    ]);
}

function insertCompany(string $ws, string $siren, string $denomination): int
{
    return DB::table('companies')->insertGetId([
        'workspace_id' => $ws,
        'siren' => $siren,
        'denomination' => $denomination,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function insertAutonomousMedia(string $ws, array $attrs): int
{
    return DB::table('media')->insertGetId(array_merge([
        'workspace_id' => $ws,
        'name' => 'Média',
        'media_type' => 'presse_quotidien',
        'created_at' => now(),
        'updated_at' => now(),
    ], $attrs));
}

it('rattache par SIREN exact', function () {
    $ws = linkWorkspace();
    $companyId = insertCompany($ws->id, '123456789', 'Société Éditrice Alpha');
    $mediaId = insertAutonomousMedia($ws->id, ['name' => 'Journal Alpha', 'siren' => '123456789']);

    $this->artisan('media:link-to-companies')->assertExitCode(0);

    expect((int) DB::table('media')->where('id', $mediaId)->value('company_id'))->toBe($companyId);
});

it('rattache par nom exact UNIQUE (publisher)', function () {
    $ws = linkWorkspace();
    $companyId = insertCompany($ws->id, '222222222', 'Editions Beta');
    // publisher exact (accents/casse absorbés par normalize_name).
    $mediaId = insertAutonomousMedia($ws->id, ['name' => 'Le Titre Beta', 'publisher' => 'Éditions Beta']);

    $this->artisan('media:link-to-companies')->assertExitCode(0);

    expect((int) DB::table('media')->where('id', $mediaId)->value('company_id'))->toBe($companyId);
});

it('NE rattache PAS un nom ambigu (2 companies même nom normalisé)', function () {
    $ws = linkWorkspace();
    insertCompany($ws->id, '333333333', 'Groupe Presse');
    insertCompany($ws->id, '444444444', 'Groupe Presse'); // homonyme → ambiguïté
    $mediaId = insertAutonomousMedia($ws->id, ['name' => 'Mag Gamma', 'publisher' => 'Groupe Presse']);

    $this->artisan('media:link-to-companies')->assertExitCode(0);

    // Garde-fou HAVING count(*)=1 : aucun rattachement.
    expect(DB::table('media')->where('id', $mediaId)->value('company_id'))->toBeNull();
});

it('NE rattache PAS quand aucune company ne correspond', function () {
    $ws = linkWorkspace();
    insertCompany($ws->id, '555555555', 'Autre Chose');
    $mediaId = insertAutonomousMedia($ws->id, ['name' => 'Média Inconnu', 'publisher' => 'Editeur Absent']);

    $this->artisan('media:link-to-companies')->assertExitCode(0);

    expect(DB::table('media')->where('id', $mediaId)->value('company_id'))->toBeNull();
});

it('est idempotent et --dry-run n\'écrit rien', function () {
    $ws = linkWorkspace();
    $companyId = insertCompany($ws->id, '666666666', 'Editeur Delta');
    $mediaId = insertAutonomousMedia($ws->id, ['name' => 'Titre Delta', 'siren' => '666666666']);

    // dry-run : ne touche rien.
    $this->artisan('media:link-to-companies', ['--dry-run' => true])->assertExitCode(0);
    expect(DB::table('media')->where('id', $mediaId)->value('company_id'))->toBeNull();

    // run réel puis 2e run : company_id stable, ne retraite que company_id IS NULL.
    $this->artisan('media:link-to-companies')->assertExitCode(0);
    $this->artisan('media:link-to-companies')->assertExitCode(0);
    expect((int) DB::table('media')->where('id', $mediaId)->value('company_id'))->toBe($companyId);
});
