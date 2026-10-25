<?php

/**
 * `crm:pharmacies:ecarter-fermees` — ecarte sans JAMAIS supprimer.
 *
 * Gardes : dry-run n'ecrit rien ; apply pose deleted_at + motif sans effacer ;
 * fiche avec contact → a_verifier_fermee sans corbeille ; inconnue de FINESS →
 * non_officine sans corbeille ; officine active gardee ; aucun nom en sortie.
 *
 * Fixtures FICTIVES (depot public).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function pefEspace(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'pef-' . substr($id, 0, 8), 'name' => 'PEF',
        'settings' => '{}', 'cost_cap_eur' => 100, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function pefFiche(string $ws, string $siren, array $attrs = []): int
{
    return (int) DB::table('companies')->insertGetId(array_merge([
        'workspace_id' => $ws, 'siren' => $siren, 'denomination' => 'ZZ FICTIVE ' . $siren,
        'naf' => '47.73Z', 'signals' => '{}', 'metadata' => '{}', 'field_origins' => '{}',
        'created_at' => now(), 'updated_at' => now(),
    ], $attrs));
}

function pefCsv(array $lignes): string
{
    $chemin = tempnam(sys_get_temp_dir(), 'pef');
    $contenu = "finess_et,siret,categorie,etat\n";
    foreach ($lignes as [$siren, $cat, $etat]) {
        $contenu .= "F{$siren},{$siren}00011,{$cat},{$etat}\n";
    }
    file_put_contents($chemin, $contenu);

    return $chemin;
}

function pefJeu(): array
{
    $ws = pefEspace();
    $ids = [
        'insee' => pefFiche($ws, '100000001', ['insee_ferme_le' => '2024-01-01']),
        'inactive' => pefFiche($ws, '100000002'),
        'active' => pefFiche($ws, '100000003'),
        'inconnue' => pefFiche($ws, '100000004'),
        'protegee' => pefFiche($ws, '100000005', ['insee_ferme_le' => '2024-01-01']),
        'autre_naf' => pefFiche($ws, '100000006', ['naf' => '10.11Z', 'insee_ferme_le' => '2024-01-01']),
    ];
    DB::table('contacts')->insert([
        'workspace_id' => $ws, 'company_id' => $ids['protegee'], 'last_name' => 'Fictif',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $csv = pefCsv([['100000002', '620', 'I'], ['100000003', '620', 'A'], ['100000005', '620', 'A'], ['100000006', '620', 'A']]);

    return [$ws, $ids, $csv];
}

test('dry-run : zero ecriture, totaux justes, aucun nom', function () {
    [$ws, $ids, $csv] = pefJeu();
    $avant = DB::table('companies')->orderBy('id')->get()->toJson();

    $code = Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv]);
    $sortie = Artisan::output();

    expect($code)->toBe(0);
    expect(DB::table('companies')->orderBy('id')->get()->toJson())->toBe($avant);
    expect($sortie)->toContain('DRY-RUN')->toContain('insee_ferme   : 1')->toContain('finess_inactif: 1')
        ->toContain('liens, sans corbeille) : 1')->toContain('non_officine')->not->toContain('ZZ FICTIVE');
});

test('apply : corbeille + motif, jamais de suppression, liens proteges', function () {
    [$ws, $ids, $csv] = pefJeu();
    $nb = DB::table('companies')->count();

    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);

    expect(DB::table('companies')->count())->toBe($nb);
    $f = fn (string $k) => DB::table('companies')->where('id', $ids[$k])->first();
    $meta = fn (string $k) => json_decode($f($k)->metadata, true);

    expect($f('insee')->deleted_at)->not->toBeNull();
    expect($meta('insee')['ecartee_motif'])->toBe('insee_ferme')->and($meta('insee'))->toHaveKey('ecartee_le');
    expect($f('inactive')->deleted_at)->not->toBeNull();
    expect($meta('inactive')['ecartee_motif'])->toBe('finess_inactif');
    expect($f('active')->deleted_at)->toBeNull();
    expect($f('inconnue')->deleted_at)->toBeNull();
    expect($meta('inconnue')['type_etablissement'])->toBe('non_officine');
    expect($f('protegee')->deleted_at)->toBeNull();
    expect($meta('protegee')['a_verifier_fermee'])->toBeTrue();
    expect($f('autre_naf')->deleted_at)->toBeNull();
    expect($meta('autre_naf'))->toBe([]);
});

test('apply est rejouable : deuxieme passage = aucun changement', function () {
    [$ws, $ids, $csv] = pefJeu();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);
    $apres = DB::table('companies')->orderBy('id')->pluck('metadata', 'id')->toJson();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--finess' => $csv, '--apply' => true]);

    expect(DB::table('companies')->orderBy('id')->pluck('metadata', 'id')->toJson())->toBe($apres);
});

test('sans --finess : seul le motif INSEE s applique', function () {
    [$ws, $ids] = pefJeu();
    Artisan::call('crm:pharmacies:ecarter-fermees', ['--apply' => true]);

    expect(DB::table('companies')->where('id', $ids['insee'])->value('deleted_at'))->not->toBeNull();
    expect(DB::table('companies')->where('id', $ids['inactive'])->value('deleted_at'))->toBeNull();
    expect(DB::table('companies')->where('id', $ids['inconnue'])->value('metadata'))->toBe('{}');
});
