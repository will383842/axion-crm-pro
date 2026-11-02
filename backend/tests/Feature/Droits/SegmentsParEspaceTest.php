<?php

/**
 * S6 — Les segments sensibles sont FERMÉS PAR DÉFAUT et réglés PAR ESPACE.
 *
 * Réglage : table `fonctions_espace` (ADR-08), derrière le drapeau
 * `crm.segments_par_espace` (ÉTEINT par défaut : tant qu'il l'est, le réglage
 * global `CRM_SEGMENTS_OUVERTS` reste seul juge — comportement d'aujourd'hui).
 */

use App\Crm\Campagnes\Segments;
use App\Models\Workspace;
use App\Socle\Espace\FonctionsEspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function espaceFactice(string $slug): string
{
    return (string) Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => $slug, 'name' => $slug, 'settings' => [],
    ])->id;
}

test('drapeau éteint : le réglage global fait foi, comme aujourd’hui (les trois segments ouverts)', function () {
    config(['crm.segments_par_espace' => false, 'crm.segments_ouverts' => '']);

    expect(config('crm.segments_par_espace'))->toBeFalse()
        ->and(Segments::ouverts(espaceFactice('ws-a')))->toBe(Segments::CONNUS);
});

test('drapeau allumé : un nouvel espace a TOUT fermé', function () {
    config(['crm.segments_par_espace' => true]);
    $nouvel = espaceFactice('ws-nouveau');

    expect(Segments::ouverts($nouvel))->toBe([])
        ->and(Segments::ouvert(Segments::PRESSE, $nouvel))->toBeFalse()
        ->and(Segments::ouvert(Segments::FEDERATIONS, $nouvel))->toBeFalse()
        ->and(Segments::ouvert(Segments::ORGANISATEURS_EVENEMENTS, $nouvel))->toBeFalse();
});

test('drapeau allumé : sans espace connu, tout est fermé (échec fermé)', function () {
    config(['crm.segments_par_espace' => true]);

    expect(Segments::ouverts(null))->toBe([]);
});

test('drapeau allumé : on ouvre un segment pour UN espace sans toucher aux autres', function () {
    config(['crm.segments_par_espace' => true]);
    $a = espaceFactice('ws-a');
    $b = espaceFactice('ws-b');

    FonctionsEspace::regler($a, Segments::PRESSE, true);

    expect(Segments::ouvert(Segments::PRESSE, $a))->toBeTrue()
        ->and(Segments::ouverts($a))->toBe([Segments::PRESSE])
        ->and(Segments::ouvert(Segments::PRESSE, $b))->toBeFalse();

    FonctionsEspace::regler($a, Segments::PRESSE, false);
    expect(Segments::ouvert(Segments::PRESSE, $a))->toBeFalse();
});

test('un segment inconnu ne peut jamais être ouvert par espace', function () {
    config(['crm.segments_par_espace' => true]);
    $a = espaceFactice('ws-a');

    expect(fn () => FonctionsEspace::regler($a, 'prospects-insee', true))
        ->toThrow(InvalidArgumentException::class);
    expect(Segments::ouvert('prospects-insee', $a))->toBeFalse();
});

test('instantané : la migration pose pour l’espace d’Axion exactement l’état d’aujourd’hui', function () {
    config(['crm.segments_par_espace' => true]);

    // L'état d'AUJOURD'HUI = les trois segments connus ouverts (`CRM_SEGMENTS_OUVERTS` vide).
    $etatAujourdhui = Segments::CONNUS;

    $migration = require base_path('database/migrations/2026_11_02_000010_fonctions_espace.php');
    $axion = espaceFactice('axion-ia');
    DB::table('fonctions_espace')->where('espace_id', $axion)->delete();
    config(['crm.segments_ouverts' => '']);
    $migration->poserEtatActuelPourAxion();

    expect(Segments::ouverts($axion))->toBe($etatAujourdhui);
});

test('la migration est réversible : aller, retour, aller', function () {
    $migration = require base_path('database/migrations/2026_11_02_000010_fonctions_espace.php');

    $migration->down();
    expect(Schema::hasTable('fonctions_espace'))->toBeFalse();
    $migration->up();
    expect(Schema::hasTable('fonctions_espace'))->toBeTrue();
    $migration->down();
    $migration->up();
    expect(Schema::hasTable('fonctions_espace'))->toBeTrue();
});
