<?php

/**
 * NR-10 — R8, les purges.
 *
 * Plan 22 : drapeau `CRM_PURGE_ENABLED` éteint ⇒ purges SAUTÉES et écrites au
 * journal ; les commandes de purge d’entreprises refusent sans `--force` ; et
 * AUCUNE purge ne touche une fiche venue de la console (ordre permanent de Will :
 * aucune destruction des données clients, `FichesProtegees::TAG_CONSOLE_CLIENT`).
 *
 * Gardes existantes rattachées au groupe : `AutomatismesDePurgeTest`
 * (drapeau fermé/ouvert, dry-run, portée), `PurgesDestructricesTest` (plafond et
 * `--force`).
 *
 * Ajout du filet : « fiche venue de la console jamais purgée », sur CHAQUE
 * commande qui peut supprimer une fiche ou une personne, avec un TÉMOIN (une
 * fiche ordinaire, elle, est bien purgée — sinon une purge qui ne fait rien
 * passerait pour irréprochable).
 *
 * Fixtures FICTIVES (`@example.test`).
 */

use App\Crm\FichesProtegees;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\NonRegression\EspacesFactices;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->espaces = EspacesFactices::creer();
    $this->tagConsole = (int) DB::table('tags')->insertGetId([
        'workspace_id' => $this->espaces['a'], 'slug' => FichesProtegees::TAG_CONSOLE_CLIENT, 'name' => 'Client console',
        'category' => 'intent', 'kind' => 'auto', 'rules' => '{}', 'is_locked' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
});

/** @param  array<string, mixed>  $en_plus */
function nr10Fiche(object $t, string $nom, bool $console, array $en_plus = []): int
{
    $id = (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $t->espaces['a'], 'siren' => (string) random_int(100000000, 999999999),
        'denomination' => $nom, 'legal_form' => null, 'created_at' => now(), 'updated_at' => now(),
    ], $en_plus));
    if ($console) {
        DB::table('company_tag')->insert([
            'company_id' => $id, 'tag_id' => $t->tagConsole, 'workspace_id' => $t->espaces['a'],
            'assigned_at' => now(), 'assigned_by' => 'auto-rule',
        ]);
    }

    return $id;
}

function nr10Existe(int $id): bool
{
    return DB::table('companies')->where('id', $id)->exists();
}

test('NR-10 purge-non-commercial ne supprime jamais une fiche console, et supprime la fiche ordinaire (témoin)', function () {
    $console = nr10Fiche($this, 'Client console', true);
    $ordinaire = nr10Fiche($this, 'Association ordinaire', false);
    for ($i = 0; $i < 8; $i++) {
        nr10Fiche($this, 'Societe ' . $i, false, ['legal_form' => '5710']);
    }

    Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    expect(nr10Existe($console))->toBeTrue()
        ->and(nr10Existe($ordinaire))->toBeFalse();
});

test('NR-10 purge-non-diffusible ne supprime jamais une fiche console, et supprime la fiche [ND] ordinaire (témoin)', function () {
    $console = nr10Fiche($this, '[ND]', true, ['legal_form' => '5710']);
    $ordinaire = nr10Fiche($this, '[ND]', false, ['legal_form' => '5710']);
    for ($i = 0; $i < 8; $i++) {
        nr10Fiche($this, 'Societe ' . $i, false, ['legal_form' => '5710']);
    }

    Artisan::call('prospection:purge-non-diffusible', ['--force' => true]);

    expect(nr10Existe($console))->toBeTrue()
        ->and(nr10Existe($ordinaire))->toBeFalse();
});

test('NR-10 les purges d’entreprises refusent sans --force quand la proportion dépasse le plafond', function () {
    foreach (range(1, 9) as $i) {
        nr10Fiche($this, 'Sans forme ' . $i, false);
    }
    nr10Fiche($this, 'Societe', false, ['legal_form' => '5710']);

    Artisan::call('prospection:purge-non-commercial');

    expect(DB::table('companies')->count())->toBe(10)
        ->and(Artisan::output())->toContain('REFUS');
});

test('NR-10 retention:purge --all-workspaces ne supprime aucune fiche ni personne, console ou non', function () {
    $console = nr10Fiche($this, 'Client console', true);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espaces['a'], 'company_id' => $console, 'last_name' => 'Client',
        'email' => 'client@nr10.example.test', 'discovery_source' => 'console',
        'created_at' => now()->subYears(5), 'updated_at' => now()->subYears(5),
    ]);

    Artisan::call('retention:purge', ['--all-workspaces' => true, '--force' => true]);

    expect(nr10Existe($console))->toBeTrue()
        ->and(DB::table('contacts')->where('email', 'client@nr10.example.test')->exists())->toBeTrue();
});

test('NR-10 drapeau éteint : la purge des personnes froides refuse et ne supprime rien', function () {
    config(['crm.purges_enabled' => false]);
    $console = nr10Fiche($this, 'Client console', true);
    DB::table('contacts')->insert([
        'workspace_id' => $this->espaces['a'], 'company_id' => $console, 'last_name' => 'Ancien',
        'email' => 'ancien@nr10.example.test', 'created_at' => now()->subYears(5), 'updated_at' => now()->subYears(5),
    ]);

    $code = Artisan::call('rgpd:purge-business-prospects', ['--force' => true]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('CRM_PURGE_ENABLED')
        ->and(DB::table('contacts')->where('email', 'ancien@nr10.example.test')->exists())->toBeTrue();
});

test('NR-10 drapeau ouvert : une personne froide d’une fiche console n’est PAS purgée, une personne froide ordinaire l’est (témoin)', function () {
    config(['crm.purges_enabled' => true]);
    $console = nr10Fiche($this, 'Client console', true);
    $ordinaire = nr10Fiche($this, 'Prospect ordinaire', false);
    foreach ([[$console, 'client-froid'], [$ordinaire, 'prospect-froid']] as [$societe, $nom]) {
        DB::table('contacts')->insert([
            'workspace_id' => $this->espaces['a'], 'company_id' => $societe, 'last_name' => $nom,
            'email' => $nom . '@nr10.example.test', 'legal_basis' => 'legitimate_interest_b2b',
            'created_at' => now()->subYears(5), 'updated_at' => now()->subYears(5),
        ]);
    }
    // Des contacts récents pour que la proportion purgée reste sous le plafond.
    foreach (range(1, 9) as $i) {
        DB::table('contacts')->insert([
            'workspace_id' => $this->espaces['a'], 'company_id' => $ordinaire, 'last_name' => 'recent' . $i,
            'email' => "recent{$i}@nr10.example.test", 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    Artisan::call('rgpd:purge-business-prospects', ['--force' => true]);

    expect(DB::table('contacts')->where('email', 'client-froid@nr10.example.test')->exists())->toBeTrue()
        ->and(DB::table('contacts')->where('email', 'prospect-froid@nr10.example.test')->exists())->toBeFalse();
});
