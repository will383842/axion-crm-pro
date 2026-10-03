<?php

/**
 * LIBELLÉS D'ACTIVITÉ NAF (lot N7, 2026-10-03).
 *
 * Constat : `naf_subclasses` VIDE en production, l'API ne rendait aucun
 * `naf_label`, l'écran retombait sur la division. Ce que ces gardes tiennent :
 *
 *  1. `crm:referentiels:charger-naf` charge les cinq niveaux depuis les CSV ;
 *  2. il est IDEMPOTENT (un second passage n'écrit rien) ;
 *  3. il ne SUPPRIME jamais une ligne, ne réécrit jamais `is_artisanat`, et
 *     corrige un libellé périmé ;
 *  4. la liste ET la fiche rendent `naf_label`, depuis `naf_rev2` ou `naf`,
 *     avec ou sans point ; code inconnu, de 1993 non converti, NAP ou vide →
 *     `null`.
 *
 * Fixtures FICTIVES (dépôt public).
 */

use App\Models\Company;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/** @return array<string, int> nombre de lignes par table de référence */
function lnComptes(): array
{
    $res = [];
    foreach (['naf_sections', 'naf_divisions', 'naf_groups', 'naf_classes', 'naf_subclasses'] as $t) {
        $res[$t] = DB::table($t)->count();
    }

    return $res;
}

function lnLibelle(string $code): ?string
{
    $v = DB::table('naf_subclasses')->where('code', $code)->value('label');

    return $v === null ? null : (string) $v;
}

test('le chargement remplit les cinq niveaux depuis les CSV du dépôt', function () {
    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();

    expect(lnComptes())->toBe([
        'naf_sections' => 21,
        'naf_divisions' => 88,
        'naf_groups' => 272,
        'naf_classes' => 615,
        'naf_subclasses' => 732,
    ])
        ->and(lnLibelle('6201Z'))->toBe('Programmation informatique')
        ->and(DB::table('naf_subclasses')->where('code', '6201Z')->value('class_code'))->toBe('6201')
        ->and(DB::table('naf_divisions')->where('code', '62')->value('section_code'))->toBe('J');
});

test('idempotent : un second passage n écrit rien et laisse la table identique', function () {
    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();
    $avant = DB::table('naf_subclasses')->orderBy('code')->get()->toArray();

    DB::enableQueryLog();
    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();
    $ecritures = array_filter(
        DB::getQueryLog(),
        static fn (array $q): bool => preg_match('/^\s*(insert|update|delete)/i', $q['query']) === 1,
    );
    DB::disableQueryLog();

    expect($ecritures)->toBe([])
        ->and(DB::table('naf_subclasses')->orderBy('code')->get()->toArray())->toEqual($avant);
});

test('aucune suppression : une ligne absente des CSV est conservée ; is_artisanat n est jamais réécrit ; un libellé périmé est corrigé', function () {
    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();

    // Une sous-classe locale (hors nomenclature) et un drapeau artisanat posé à la main.
    DB::table('naf_subclasses')->insert(['code' => '6201Y', 'class_code' => '6201', 'label' => 'ZZ ligne locale', 'is_artisanat' => false]);
    DB::table('naf_subclasses')->where('code', '4322A')->update(['is_artisanat' => true]);
    DB::table('naf_subclasses')->where('code', '6201Z')->update(['label' => 'ZZ libellé périmé']);

    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();

    expect(lnLibelle('6201Y'))->toBe('ZZ ligne locale')
        ->and((bool) DB::table('naf_subclasses')->where('code', '4322A')->value('is_artisanat'))->toBeTrue()
        ->and(lnLibelle('6201Z'))->toBe('Programmation informatique')
        ->and(DB::table('naf_subclasses')->count())->toBe(733);
});

test('--dry-run ne écrit rien', function () {
    $this->artisan('crm:referentiels:charger-naf', ['--dry-run' => true])->assertSuccessful();

    expect(DB::table('naf_subclasses')->count())->toBe(0)
        ->and(DB::table('naf_sections')->count())->toBe(0);
});

// ── L'API ───────────────────────────────────────────────────────────────────

/** @param  array<string, mixed>  $champs */
function lnFiche(string $espace, array $champs): int
{
    $c = Company::create([
        'workspace_id' => $espace, 'siren' => (string) random_int(100000000, 999999999),
        'denomination' => 'ZZ ' . Str::random(6), 'naf' => $champs['naf'] ?? null,
        'signals' => [], 'metadata' => [],
    ]);
    if (array_key_exists('naf_rev2', $champs)) {
        DB::table('companies')->where('id', $c->id)->update(['naf_rev2' => $champs['naf_rev2']]);
    }

    return (int) $c->id;
}

function lnConnecter(object $test): string
{
    $test->seed(PermissionsAndRolesSeeder::class);
    $espace = Workspace::create(['id' => (string) Str::uuid(), 'slug' => 'ws-naf', 'name' => 'WS NAF', 'settings' => []]);
    $u = User::create([
        'id' => (string) Str::uuid(), 'email' => 'naf@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $espace->id, 'first_login_completed_at' => now(),
    ]);
    setPermissionsTeamId($espace->id);
    $u->assignRole('admin');
    $test->actingAs($u);

    return (string) $espace->id;
}

test('la liste et la fiche rendent naf_label ; code inconnu ou non convertible → null', function () {
    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();
    $espace = lnConnecter($this);

    $attendus = [
        lnFiche($espace, ['naf' => '62.01Z']) => 'Programmation informatique',
        // Écriture sans point, minuscule.
        lnFiche($espace, ['naf' => '8130z']) => 'Services d\'aménagement paysager',
        // Code de 1993 : le libellé du code rév. 2 retenu par la table de passage.
        lnFiche($espace, ['naf' => '52.1D', 'naf_rev2' => '47.11F']) => 'Hypermarchés',
        // Code de 1993 non converti, NAP 1973, code inconnu, vide : rien d'inventé.
        lnFiche($espace, ['naf' => '52.1D']) => null,
        lnFiche($espace, ['naf' => '67.01']) => null,
        lnFiche($espace, ['naf' => '99.99Z']) => null,
        lnFiche($espace, ['naf' => '62.01ZX']) => null,
        lnFiche($espace, []) => null,
    ];

    foreach (['/api/v1/companies?per_page=50', '/api/v1/companies?vue=liste&per_page=50'] as $url) {
        $lignes = collect($this->getJson($url)->assertOk()->json('data'))->keyBy('id');
        expect($lignes)->toHaveCount(count($attendus));
        foreach ($attendus as $id => $libelle) {
            expect(array_key_exists('naf_label', $lignes[$id]))->toBeTrue("{$url} : naf_label absent")
                ->and($lignes[$id]['naf_label'])->toBe($libelle, "{$url} : fiche {$id}");
        }
    }

    foreach ($attendus as $id => $libelle) {
        $fiche = $this->getJson("/api/v1/companies/{$id}")->assertOk()->json();
        expect(array_key_exists('naf_label', $fiche))->toBeTrue()
            ->and($fiche['naf_label'])->toBe($libelle, "fiche {$id}");
    }
});

test('la liste garde sa pagination, son total et son tri avec le libellé', function () {
    $this->artisan('crm:referentiels:charger-naf')->assertSuccessful();
    $espace = lnConnecter($this);
    foreach (range(1, 3) as $i) {
        lnFiche($espace, ['naf' => '62.01Z']);
    }

    $r = $this->getJson('/api/v1/companies?per_page=2&sort=-denomination')->assertOk();
    $noms = array_column($r->json('data'), 'denomination');
    $tries = $noms;
    rsort($tries);

    expect($r->json('degraded'))->toBeNull()
        ->and($r->json('meta.total'))->toBe(3)
        ->and($r->json('meta.last_page'))->toBe(2)
        ->and($noms)->toBe($tries)
        ->and(array_column($r->json('data'), 'naf_label'))->toBe(['Programmation informatique', 'Programmation informatique']);
});
