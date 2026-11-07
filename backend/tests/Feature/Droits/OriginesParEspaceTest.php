<?php

/**
 * L6 — les lectures d'origines ne s'ouvrent que dans l'espace où Will l'a décidé.
 * Fonction d'espace `origines.lecture` (table `fonctions_espace`, ADR-08) :
 * sans ligne = FERMÉE. 404 (pas 403) pour ne pas confirmer l'existence.
 */

use App\Crm\Campagnes\Segments;
use App\Socle\Espace\FonctionsEspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    OriginesJeu::allumer();
    $this->axion = OriginesJeu::espace('axion-ia');
});

test('L6 1 — espace business avec la ligne ouverte : 200 sur /crm/origines/apercu', function () {
    OriginesJeu::ouvrir($this->axion->id);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'l6.un@example.invalid'));

    $this->getJson('/api/v1/crm/origines/apercu')->assertOk();
});

test('L6 2 — sans ligne ou ligne fermée : 404 ; espace vivier : refusé', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'l6.deux@example.invalid'));

    $this->getJson('/api/v1/crm/origines/apercu')->assertNotFound();

    OriginesJeu::ouvrir($this->axion->id, false);
    $this->getJson('/api/v1/crm/origines/apercu')->assertNotFound();

    $vivier = OriginesJeu::espace('vivier-candidats');
    OriginesJeu::ouvrir($vivier->id);
    $this->actingAs(OriginesJeu::utilisateur($vivier->id, 'admin', 'l6.vivier@example.invalid'));
    expect($this->getJson('/api/v1/crm/origines/apercu')->status())->toBeIn([403, 404]);
});

test('L6 2b — interrupteur général éteint : 404 même avec la ligne ouverte (une fusion ne change rien)', function () {
    config(['origines.lecture_enabled' => false]);
    OriginesJeu::ouvrir($this->axion->id);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'l6.off@example.invalid'));

    $this->getJson('/api/v1/crm/origines/apercu')->assertNotFound();
    expect(config('origines.lecture_enabled'))->toBeFalse();
});

test('L6 2c — par défaut (config du dépôt), l’interrupteur de lecture est ÉTEINT', function () {
    $brut = require base_path('config/origines.php');
    expect($brut['lecture_enabled'])->toBeFalse();
});

test('L6 3 — espace courant où le compte n’est pas membre : 403', function () {
    $autre = OriginesJeu::espace('ws-autre');
    OriginesJeu::ouvrir($autre->id);
    $user = OriginesJeu::utilisateur($this->axion->id, 'admin', 'l6.trois@example.invalid');
    DB::table('users')->where('id', $user->id)->update(['current_workspace_id' => $autre->id]);
    $this->actingAs($user->refresh());

    $this->getJson('/api/v1/crm/origines/apercu')->assertForbidden();
});

test('L6 4 — garde anti-dérive : aucun segment de campagne « origines » ou « réseaux »', function () {
    foreach (Segments::CONNUS as $segment) {
        expect($segment)->not->toContain('origine')->and($segment)->not->toContain('reseau');
    }
    expect(FonctionsEspace::ORIGINES_LECTURE)->toBe('origines.lecture');
});

test('L6 5 — la migration pose la ligne pour l’espace d’Axion seulement (additive, idempotente)', function () {
    $migration = require database_path('migrations/2026_11_08_000010_fonctions_espace_origines.php');
    $migration->up();
    $migration->up();

    expect(FonctionsEspace::ouverte($this->axion->id, 'origines.lecture'))->toBeTrue();
    $autre = OriginesJeu::espace('ws-sans-ligne');
    expect(FonctionsEspace::ouverte($autre->id, 'origines.lecture'))->toBeFalse()
        ->and(DB::table('fonctions_espace')->where('fonction', 'origines.lecture')->count())->toBe(1);
});
