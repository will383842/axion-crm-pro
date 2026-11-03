<?php

/**
 * GARDE DES COMMANDES DESTRUCTIVES — audit 360, B15-004 et B15-008.
 *
 * `prospection:purge-non-commercial` supprimait sur la condition
 * `legal_form IS NULL OR left(legal_form,1) <> '5'`. Le `IS NULL` est le piège :
 * sur la base de volume, la forme juridique est inconnue pour l'essentiel des
 * lignes — un `artisan` lancé sans y penser effaçait **presque toute la base**,
 * sans confirmation ni retour possible.
 *
 * Sept commandes destructives existaient, trois planifiées, et **aucun test ne
 * disait ce qu'elles suppriment**.
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espacePurge(): string
{
    $id = (string) Str::uuid();
    DB::table('workspaces')->insert([
        'id' => $id, 'slug' => 'purge-' . Str::random(8), 'name' => 'Espace purge',
        'settings' => '{}', 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
    ]);

    return $id;
}

function creerEntreprises(string $espace, int $nombre, ?string $formeJuridique): void
{
    for ($i = 0; $i < $nombre; $i++) {
        DB::table('companies')->insert([
            'workspace_id' => $espace,
            'denomination' => 'Entreprise ' . Str::random(6),
            'siren' => (string) random_int(100000000, 999999999),
            'legal_form' => $formeJuridique,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

// L3c-0 (ADR contrat v3 § 6, F1) : les deux commandes de purge d'entreprises
// refusent SANS CONDITION, car les fiches [ND] sont des entrepreneurs
// individuels (des personnes) et `contacts.company_id` est en cascade.

test('B15-004 / L3c-0 — la purge refuse, meme avec --force, et ne supprime RIEN', function () {
    $espace = espacePurge();
    creerEntreprises($espace, 2, '1000');
    creerEntreprises($espace, 8, '5710');

    $code = Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    expect($code)->toBe(1);
    expect(DB::table('companies')->count())->toBe(10);
    expect(Artisan::output())->toContain('neutralisée');
});

test('B15-004 / L3c-0 — l essai a blanc ne supprime RIEN non plus', function () {
    $espace = espacePurge();
    creerEntreprises($espace, 3, null);

    $code = Artisan::call('prospection:purge-non-commercial', ['--dry-run' => true]);

    expect($code)->toBe(1);
    expect(DB::table('companies')->count())->toBe(3);
});

test('B15-004 / L3c-0 — la purge des non diffusibles refuse aussi, meme avec --force', function () {
    $espace = espacePurge();
    for ($i = 0; $i < 2; $i++) {
        DB::table('companies')->insert([
            'workspace_id' => $espace, 'denomination' => '[ND]',
            'siren' => (string) random_int(100000000, 999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    creerEntreprises($espace, 8, '5710');

    $code = Artisan::call('prospection:purge-non-diffusible', ['--force' => true]);

    expect($code)->toBe(1);
    expect(DB::table('companies')->count())->toBe(10);
});
