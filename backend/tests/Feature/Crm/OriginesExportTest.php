<?php

/**
 * L2-back — export CSV nominatif des origines : `data.export` ET `contacts.view_pii`.
 */

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
    OriginesJeu::ouvrir($this->axion->id);
    $ws = $this->axion->id;
    $this->ok = OriginesJeu::personne($ws, 'oe.ok@example.invalid', [OriginesJeu::entree(['post_ref' => 'post_ref_exp001'])]);
    $this->opposee = OriginesJeu::personne($ws, 'oe.opposee@example.invalid', [OriginesJeu::entree(['post_ref' => 'post_ref_exp002'])], ['opposition_at' => now()]);
    OriginesJeu::personne($ws, 'oe.masquee@example.invalid', [OriginesJeu::entree(['post_ref' => 'post_ref_exp003', 'masque_le' => '2026-11-04T00:00:00Z'])]);
    foreach ([$this->ok, $this->opposee] as $id) {
        DB::table('abonnements')->insert([
            'workspace_id' => $ws, 'personne_id' => $id, 'canal' => 'lettre', 'statut' => 'abonne',
            'consent_version' => 'newsletter-v1', 'consent_at' => now(), 'dernier_evenement_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
});

test('OE 1 — sans data.export : 403', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'viewer', 'oe.viewer@example.invalid'));
    $this->get('/api/v1/crm/origines/export')->assertForbidden();
});

test('OE 2 — avec data.export mais SANS contacts.view_pii : 403', function () {
    $user = OriginesJeu::utilisateur($this->axion->id, 'viewer', 'oe.sanspii@example.invalid');
    setPermissionsTeamId($this->axion->id);
    $user->givePermissionTo('data.export');
    $this->actingAs($user);

    $this->get('/api/v1/crm/origines/export')->assertForbidden();
});

test('OE 3 — avec les deux : CSV, opposées et entrées masquées exclues, audit sans adresse', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'owner', 'oe.owner@example.invalid'));

    $csv = $this->get('/api/v1/crm/origines/export')->assertOk()->streamedContent();
    expect($csv)->toContain('oe.ok@example.invalid')->toContain('post_ref_exp001')
        ->and($csv)->not->toContain('oe.opposee@example.invalid')
        ->and($csv)->not->toContain('oe.masquee@example.invalid')
        ->and($csv)->not->toContain('post_ref_exp003')
        ->and($csv)->not->toContain('commentaire_ref');

    $audit = DB::table('audit_logs')->orderByDesc('id')->limit(5)->get()->map(fn ($l) => json_encode($l))->implode('|');
    expect($audit)->not->toContain('oe.ok@example.invalid');
});

test('OE 4 — plafond : l’export tronqué le dit', function () {
    config(['exports.plafond_lignes' => 1]);
    OriginesJeu::personne($this->axion->id, 'oe.deux@example.invalid', [OriginesJeu::entree(['post_ref' => 'post_ref_exp010'])]);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'owner', 'oe.owner2@example.invalid'));

    $r = $this->get('/api/v1/crm/origines/export')->assertOk();
    expect($r->streamedContent())->toContain('EXPORT TRONQUE');
});

test('OE 5 — espace fermé : 404 même avec les deux permissions', function () {
    OriginesJeu::ouvrir($this->axion->id, false);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'owner', 'oe.ferme@example.invalid'));
    $this->get('/api/v1/crm/origines/export')->assertNotFound();
});
