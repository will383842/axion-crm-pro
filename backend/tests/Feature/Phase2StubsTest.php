<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-p2', 'name' => 'WS Phase 2', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'p2@example.com', 'name' => 'P2',
        'password_hash' => Hash::make('P2TestPassword12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->actingAs($this->user);
});

// Sprint 19.7 — /campaigns n'est plus un stub Phase 2, c'est un endpoint complet
// (Scraping Campaigns). Voir CampaignsTest.php.

test('Phase 2 cold-email endpoint returns 501', function () {
    $this->getJson('/api/v1/cold-email')->assertStatus(501);
});

test('Phase 2 linkedin endpoint returns 501', function () {
    $this->getJson('/api/v1/linkedin')->assertStatus(501);
});

// F7 (étape 0) — `/crm` et `/analytics` NE SONT PLUS des bouchons Phase 2 :
// les deux fourre-tout `Route::any(...)` ont été retirés de routes/api.php,
// parce que ces noms sont ceux du chantier CRM cible. Les deux cas qui
// attendaient un 501 testaient donc un comportement supprimé volontairement.
// Le comportement RÉEL attendu est vérifié par
// tests/Feature/PasDeStub501SousCrmEtAnalyticsTest.php.

// ⚠️ Ce cas interrogeait `/api/v1/campaigns`, qui n'est plus un bouchon depuis
// le Sprint 19.7 (endpoint complet Scraping Campaigns, cf. CampaignsTest.php) :
// il ne renvoie donc plus de marqueur `sprint`. On vérifie le marqueur sur un
// endpoint réellement encore bouchonné.
test('501 response includes sprint Phase 2 marker', function () {
    $r = $this->getJson('/api/v1/cold-email');
    $r->assertStatus(501);
    expect($r->json('sprint'))->toBe('Phase 2');
});
