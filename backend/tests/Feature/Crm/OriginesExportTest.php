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

    // UNE seule ligne d'audit pour cet export, avec le compte de lignes et les filtres, SANS donnée personnelle.
    $lignes = DB::table('audit_logs')->where('path', 'like', '%origines/export%')->get();
    expect($lignes)->toHaveCount(1);
    $brut = json_encode($lignes[0]);
    expect($lignes[0]->path)->toContain('lignes=1')
        ->and($brut)->not->toContain('oe.ok@example.invalid')
        ->and($brut)->not->toContain('post_ref_exp001')
        ->and($brut)->not->toContain('Zoé');
    $tout = DB::table('audit_logs')->get()->map(fn ($l) => json_encode($l))->implode('|');
    expect($tout)->not->toContain('oe.ok@example.invalid');
});

test('OE 3 bis — l’audit porte les filtres validés et le compte, jamais d’adresse', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'owner', 'oe.owner3@example.invalid'));
    $this->get('/api/v1/crm/origines/export?reseau=instagram&du=2026-10-01')->assertOk()->streamedContent();

    $ligne = DB::table('audit_logs')->where('path', 'like', '%origines/export%')->first();
    expect($ligne)->not->toBeNull()
        ->and($ligne->path)->toContain('reseau=instagram')->toContain('du=2026-10-01')->toContain('lignes=');
});

test('OE 3 ter — flux interrompu par une exception : la ligne d’audit existe quand même', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'owner', 'oe.owner4@example.invalid'));
    // Panne simulée pendant le flux : la lecture par lots (chunkById sur personnes.id) échoue.
    DB::listen(function ($requete) {
        if (str_contains($requete->sql, 'personnes.id') && str_contains($requete->sql, 'limit')) {
            throw new RuntimeException('panne simulée pendant le flux');
        }
    });

    try {
        $this->get('/api/v1/crm/origines/export?reseau=instagram')->streamedContent();
    } catch (Throwable) {
        // l'interruption est voulue
    }

    $lignes = DB::table('audit_logs')->where('path', 'like', '%origines/export%')->get();
    expect($lignes)->toHaveCount(1)
        ->and($lignes[0]->path)->toContain('lignes=0')->toContain('reseau=instagram')
        ->and(json_encode($lignes[0]))->not->toContain('oe.ok@example.invalid');
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
