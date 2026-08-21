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

test('B15-004 — la purge REFUSE d effacer la quasi-totalite de la base', function () {
    $espace = espacePurge();
    // Le cas réel : la forme juridique est inconnue pour l'essentiel des lignes.
    creerEntreprises($espace, 9, null);
    creerEntreprises($espace, 1, '5710');

    Artisan::call('prospection:purge-non-commercial', ['--force' => false]);

    // 90 % dépasse le plafond : la commande refuse, et RIEN n'est supprimé.
    expect(DB::table('companies')->count())->toBe(10);
    expect(Artisan::output())->toContain('REFUS');
});

test('B15-004 — l essai a blanc ne supprime RIEN', function () {
    $espace = espacePurge();
    creerEntreprises($espace, 3, null);

    Artisan::call('prospection:purge-non-commercial', ['--dry-run' => true]);

    expect(DB::table('companies')->count())->toBe(3);
    expect(Artisan::output())->toContain('Essai à blanc');
});

test('B15-004 — TEMOIN : sous le plafond et avec --force, la purge FAIT son travail', function () {
    $espace = espacePurge();
    // 2 non-commerciales sur 10 = 20 %, sous le plafond de 30 %.
    creerEntreprises($espace, 2, '1000');
    creerEntreprises($espace, 8, '5710');

    Artisan::call('prospection:purge-non-commercial', ['--force' => true]);

    // Sans ce témoin, une garde qui refuserait TOUT passerait pour une réussite
    // — et la commande deviendrait inutile.
    expect(DB::table('companies')->count())->toBe(8);
});

test('B15-004 — la purge des non diffusibles porte la MEME garde', function () {
    $espace = espacePurge();
    for ($i = 0; $i < 9; $i++) {
        DB::table('companies')->insert([
            'workspace_id' => $espace, 'denomination' => '[ND]',
            'siren' => (string) random_int(100000000, 999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    creerEntreprises($espace, 1, '5710');

    Artisan::call('prospection:purge-non-diffusible');

    expect(DB::table('companies')->count())->toBe(10);
    expect(Artisan::output())->toContain('REFUS');
});
