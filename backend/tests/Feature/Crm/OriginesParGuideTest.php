<?php

/**
 * PONT-LINKEDIN PO-G — `GET /api/v1/crm/origines/par-guide` : liste des guides proposables.
 * Calque de `par-mot` : trois portes (drapeau, fonction d'espace, v3), lecture seule, entrées
 * masquées exclues, cloisonnement par espace. Adresses en `@example.invalid`.
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
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'pg.admin@example.invalid'));
    $ws = $this->axion->id;

    OriginesJeu::personne($ws, 'pg.un@example.invalid', [
        OriginesJeu::entree(['guide' => 'guide-ia', 'reseau' => 'instagram', 'post_ref' => 'post_ref_pg0001']),
        OriginesJeu::entree(['guide' => 'guide-vente', 'reseau' => 'linkedin', 'post_ref' => 'post_ref_pg0002']),
    ]);
    OriginesJeu::personne($ws, 'pg.deux@example.invalid', [
        OriginesJeu::entree(['guide' => 'guide-ia', 'reseau' => 'tiktok', 'post_ref' => 'post_ref_pg0003']),
        OriginesJeu::entree(['guide' => 'guide-secret', 'reseau' => 'tiktok', 'post_ref' => 'post_ref_pg0004', 'masque_le' => '2026-11-04T00:00:00Z']),
    ]);
});

test('PG 1 — drapeau éteint (défaut) : 404', function () {
    config(['origines.lecture_enabled' => false]);
    $this->getJson('/api/v1/crm/origines/par-guide')->assertNotFound();
});

test('PG 2 — fonction d\'espace fermée : 404', function () {
    OriginesJeu::ouvrir($this->axion->id, false);
    $this->getJson('/api/v1/crm/origines/par-guide')->assertNotFound();
});

test('PG 3 — v3 absent : 200 {actif:false}, aucune lecture des origines', function () {
    config(['origines.v3_enabled' => false]);
    DB::enableQueryLog();
    $this->getJson('/api/v1/crm/origines/par-guide')->assertOk()->assertJson(['actif' => false]);
    $lues = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'jsonb_array_elements') || str_contains($q['query'], 'personne_origines_archive'));
    expect($lues->count())->toBe(0);
});

test('PG 4 — ouvert : comptes par guide, entrée masquée exclue', function () {
    $r = $this->getJson('/api/v1/crm/origines/par-guide')->assertOk();
    $guides = collect($r->json('data'))->keyBy('guide');
    expect($r->json('actif'))->toBeTrue()
        ->and($guides['guide-ia']['demandes'])->toBe(2)
        ->and($guides['guide-ia']['personnes'])->toBe(2)
        ->and($guides['guide-vente']['demandes'])->toBe(1)
        ->and($guides->has('guide-secret'))->toBeFalse()
        ->and($r->json('meta.per_page'))->toBe(25)
        ->and($r->json('meta.total'))->toBe(2);
    expect(json_encode($r->json()))->not->toContain('guide-secret');
});

test('PG 5 — le filtre guide est accepté partout où mot l\'est ; valeur hors forme → 422 sans écho', function () {
    $a = $this->getJson('/api/v1/crm/origines/apercu?guide=guide-ia')->assertOk()->json();
    expect($a['demandes'])->toBe(2);
    $b = $this->getJson('/api/v1/crm/origines/par-reseau?guide=guide-vente')->assertOk()->json('data');
    expect($b)->toHaveCount(1)->and($b[0]['reseau'])->toBe('linkedin');
    $this->getJson('/api/v1/crm/origines/par-guide?reseau=tiktok')->assertOk()->assertJsonCount(1, 'data');

    $rep = $this->getJson('/api/v1/crm/origines/apercu?guide=%27%20OR%201=1');
    $rep->assertStatus(422);
    expect($rep->getContent())->not->toContain('OR 1=1');
});

test('PG 6 — IDOR : les guides d\'un autre espace ne sortent jamais', function () {
    $autre = OriginesJeu::espace('ws-autre-pg');
    OriginesJeu::personne($autre->id, 'pg.etrangere@example.invalid', [
        OriginesJeu::entree(['guide' => 'guide-etranger', 'post_ref' => 'post_ref_pg0009']),
    ]);
    $r = $this->getJson('/api/v1/crm/origines/par-guide')->assertOk();
    expect(json_encode($r->json()))->not->toContain('guide-etranger');
});

test('PG 7 — page bornée à 200, au-delà → 422', function () {
    $this->getJson('/api/v1/crm/origines/par-guide?page=200')->assertOk();
    $this->getJson('/api/v1/crm/origines/par-guide?page=201')->assertStatus(422);
});

test('PG 8 — throttle présent sur la route', function () {
    $route = app('router')->getRoutes()->match(request()->create('/api/v1/crm/origines/par-guide', 'GET'));
    expect(collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_starts_with($m, 'throttle:')))->toBeTrue();
});
