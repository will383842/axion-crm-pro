<?php

/**
 * Lien du guide sur la fiche (GET /api/v1/crm/personnes/{id}) et dans l'historique
 * des origines. Le lien vient de la table fermée `config/guides.php`, calculé à la
 * lecture ; rien n'est écrit. Adresses en `@example.invalid` (dépôt PUBLIC).
 */

use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\OriginesJeu;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

const LG_URL = 'https://exemple.invalid/guides/guide-ia.pdf';

beforeEach(function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    OriginesJeu::allumer();
    $this->axion = OriginesJeu::espace('axion-ia');
    OriginesJeu::ouvrir($this->axion->id);
    config([
        'guides.lien_enabled' => true,
        'guides.publics' => ['guide-ia' => ['nom' => 'Guide exemple A', 'url' => LG_URL, 'libelle' => 'Guide exemple A (PDF)']],
    ]);
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'admin', 'lg.admin@example.invalid'));
    $this->pid = OriginesJeu::personne($this->axion->id, 'lg.un@example.invalid', [OriginesJeu::entree()], [
        'origine_contenu' => json_encode(['mot' => 'guide', 'reseau' => 'instagram', 'guide' => 'guide-ia', 'date' => '2026-11-02T09:58:00Z']),
        'guides_recus' => json_encode([['guide' => 'guide-ia', 'date' => '2026-11-02T10:00:00Z'], ['guide' => 'guide-ancien-exemple', 'date' => '2026-10-15T10:00:00Z']]),
        'accords_at' => now(),
    ]);
});

test('LG 1 — interrupteur activé + identifiant connu : le lien de la table est exposé', function () {
    $r = $this->getJson("/api/v1/crm/personnes/{$this->pid}")->assertOk();
    expect($r->json('contenus_accords.lien_statut'))->toBe('disponible')
        ->and($r->json('contenus_accords.origine.lien.url'))->toBe(LG_URL)
        ->and($r->json('contenus_accords.guides.0.lien.url'))->toBe(LG_URL)
        ->and($r->json('contenus_accords.guides.1.lien'))->toBeNull(); // cas 3 : identifiant inconnu
});

test('LG 2 — interrupteur éteint : aucune URL dans la réponse', function () {
    config(['guides.lien_enabled' => false]);
    $r = $this->getJson("/api/v1/crm/personnes/{$this->pid}")->assertOk();
    expect($r->json('contenus_accords.lien_statut'))->toBe('eteint')
        ->and($r->json('contenus_accords.origine.lien'))->toBeNull()
        ->and($r->getContent())->not->toContain('exemple.invalid/guides');
});

test('LG 3 — URL http dans la table : refusée, pas de lien', function () {
    config(['guides.publics' => ['guide-ia' => ['url' => 'http://exemple.invalid/guides/guide-ia.pdf', 'libelle' => 'x']]]);
    $r = $this->getJson("/api/v1/crm/personnes/{$this->pid}")->assertOk();
    expect($r->json('contenus_accords.origine.lien'))->toBeNull()->and($r->getContent())->not->toContain('exemple.invalid/guides');
});

test('LG 4 — profil lecture seule (viewer) : statut refusé, aucune URL', function () {
    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'viewer', 'lg.viewer@example.invalid'));
    $r = $this->getJson("/api/v1/crm/personnes/{$this->pid}")->assertOk();
    expect($r->json('contenus_accords.lien_statut'))->toBe('refuse')
        ->and($r->json('contenus_accords.origine.lien'))->toBeNull()
        ->and($r->getContent())->not->toContain('exemple.invalid/guides');
});

test('LG 5 — l’historique des origines porte `lien_guide` (droit requis), sans jeton ni donnée de personne', function () {
    $h = $this->getJson("/api/v1/crm/personnes/{$this->pid}/origines")->assertOk();
    expect($h->json('data.0.lien_guide.url'))->toBe(LG_URL)->and($h->getContent())->not->toContain('?t=');

    $this->actingAs(OriginesJeu::utilisateur($this->axion->id, 'viewer', 'lg.viewer2@example.invalid'));
    $h2 = $this->getJson("/api/v1/crm/personnes/{$this->pid}/origines")->assertOk();
    expect($h2->json('data.0.lien_guide'))->toBeNull()->and($h2->getContent())->not->toContain('exemple.invalid/guides');
});

test('LG 6 — lecture seule : rien n’est écrit ni supprimé', function () {
    $avant = DB::table('personnes')->count();
    $this->getJson("/api/v1/crm/personnes/{$this->pid}")->assertOk();
    expect(DB::table('personnes')->count())->toBe($avant);
});
