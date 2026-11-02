<?php

/**
 * NR-04 — R2, la garde Sirene (fiches non diffusibles ou fermées).
 *
 * Une fiche non diffusible ou fermée n’est JAMAIS contactable ; la sonde de
 * 06:20 sort en échec si une fiche est marquée « [ND] » ; et, décision C3
 * (« sans suppression »), ni la préparation des destinataires ni la sonde ne
 * SUPPRIMENT jamais la fiche : on écarte, on n’efface pas.
 *
 * Gardes existantes rattachées au groupe : `OppositionInseeNonDiffusibleTest`,
 * `PurgeNonDiffusibleVariantesTest`, `SondeNonDiffusiblesTest`.
 */

use App\Console\Commands\CrmSondeNonDiffusibles;
use App\Crm\FichesProtegees;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\Feature\NonRegression\EspacesFactices;
use Tests\Support\ResolveurDnsSimule;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espace = (string) Str::uuid();
    $slug = 'nr04-' . Str::lower(Str::random(6));
    Workspace::create(['id' => $this->espace, 'slug' => $slug, 'name' => 'NR-04']);
    config(['crm.ingest.business_workspace' => $slug]);

    $this->tag = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espace, 'slug' => FichesProtegees::TAGS[0], 'name' => 'Organisateurs',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

afterEach(function () {
    foreach ($GLOBALS['nr04_fichiers'] ?? [] as $f) {
        @unlink($f);
    }
    $GLOBALS['nr04_fichiers'] = [];
});

function nr04Organisation(object $t, string $nom, string $courriel, array $en_plus = []): int
{
    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $t->espace, 'siren' => null, 'country_code' => 'FR', 'foreign_id' => 'evt:' . Str::slug($nom),
        'entity_nature' => 'reseau', 'denomination' => $nom, 'email_generic' => $courriel,
        'created_at' => now(), 'updated_at' => now(),
    ], $en_plus));
    DB::table('company_tag')->insert([
        'company_id' => $id, 'tag_id' => $t->tag, 'workspace_id' => $t->espace,
        'assigned_at' => now(), 'assigned_by' => 'auto-rule',
    ]);

    return $id;
}

/** @return array{emails: list<string>, bilan: string} */
function nr04Liste(): array
{
    ResolveurDnsSimule::toutVerifier();
    $sortie = tempnam(sys_get_temp_dir(), 'nr04-');
    $GLOBALS['nr04_fichiers'][] = $sortie;
    Artisan::call('crm:campagne:destinataires', ['segment' => 'organisateurs-evenements', 'sortie' => $sortie]);
    $bilan = Artisan::output();

    return [
        'emails' => array_values(array_map(fn ($l) => json_decode($l, true)['email'], file($sortie, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [])),
        'bilan' => $bilan,
    ];
}

test('NR-04 une fiche non diffusible ou fermée n’est jamais dans la liste, mais n’est pas supprimée', function () {
    $normale = nr04Organisation($this, 'Normale Nr04', 'normale@exemple-nr04.example.test');
    $nd = nr04Organisation($this, 'Non diffusible Nr04', 'nd@exemple-nr04.example.test', ['insee_non_diffusible_le' => '2026-09-01']);
    $fermee = nr04Organisation($this, 'Fermee Nr04', 'fermee@exemple-nr04.example.test', ['insee_ferme_le' => '2026-08-01']);

    $liste = nr04Liste();

    expect($liste['emails'])->toBe(['normale@exemple-nr04.example.test'])
        ->and($liste['bilan'])->toMatch('/ecartees_non_diffusibles\s*\|\s*1\s*\|/')
        ->and($liste['bilan'])->toMatch('/ecartees_entreprise_fermee\s*\|\s*1\s*\|/')
        // Décision C3 : on écarte, on ne supprime JAMAIS.
        ->and(DB::table('companies')->whereIn('id', [$normale, $nd, $fermee])->whereNull('deleted_at')->count())->toBe(3);
});

test('NR-04 un contact d’une fiche non diffusible n’est pas contactable non plus', function () {
    $nd = nr04Organisation($this, 'Non diffusible Nr04', 'nd@exemple-nr04.example.test', ['insee_non_diffusible_le' => '2026-09-01']);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espace, 'company_id' => $nd, 'last_name' => 'Dupont',
        'email' => 'personne@exemple-nr04.example.test', 'created_at' => now(), 'updated_at' => now(),
    ]);
    nr04Organisation($this, 'Normale Nr04', 'normale@exemple-nr04.example.test');

    expect(nr04Liste()['emails'])->toBe(['normale@exemple-nr04.example.test']);
});

test('NR-04 la sonde de 06:20 sort en échec sur une fiche « [ND] » et ne supprime rien', function () {
    foreach ([false, true] as $corbeille) {
        DB::table('companies')->insert([
            'workspace_id' => $this->espace, 'siren' => EspacesFactices::sirenInvalide(), 'denomination' => '[ND]',
            'discovery_source' => 'insee', 'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
            'deleted_at' => $corbeille ? now() : null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    $avant = DB::table('companies')->count();
    Log::spy();

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertFailed();

    Log::shouldHaveReceived('critical')->withArgs(fn (string $m): bool => str_contains($m, CrmSondeNonDiffusibles::PREFIXE_ALERTE))->once();
    expect(DB::table('companies')->count())->toBe($avant);
});

test('NR-04 la sonde se tait sur une base saine (témoin)', function () {
    nr04Organisation($this, 'Normale Nr04', 'normale@exemple-nr04.example.test');
    Log::spy();

    $this->artisan(CrmSondeNonDiffusibles::SIGNATURE_PLANIFIEE)->assertSuccessful();

    Log::shouldNotHaveReceived('critical');
});
