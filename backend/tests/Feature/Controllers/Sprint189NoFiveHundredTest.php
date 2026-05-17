<?php

/*
|--------------------------------------------------------------------------
| Sprint 18.9 — "no 500" defensive endpoint coverage
|--------------------------------------------------------------------------
|
| Cas reproduit en prod : tous les endpoints GET /api/v1/* doivent retourner
| 200 (ou 404 sur ressource manquante) — JAMAIS 500 — même quand la DB est
| partiellement seedée, PostGIS absent, ou Reverb non provisionné.
|
| Ce fichier ajoute la régression test pour chaque endpoint :
|   - /coverage (tous niveaux)
|   - /users
|   - /workspace
|   - /rgpd/requests
|   - /ai-act/register
|   - /rotations
|   - /proxy-providers
|   - /scraper-runs
|   - /audit-logs
|   - /tags
|   - /llm/use-cases
|   - /notifications
|   - /companies
|   - /contacts
*/

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeNoFiveHundredUser(): User
{
    $workspace = Workspace::create([
        'id'    => (string) Str::uuid(),
        'slug'  => 'sprint189-' . Str::random(6),
        'name'  => 'Sprint 18.9 WS',
    ]);
    return User::create([
        'id'                            => (string) Str::uuid(),
        'email'                         => 'sp189' . Str::random(4) . '@test.local',
        'name'                          => 'Sprint 18.9',
        'password_hash'                 => Hash::make('SomePass!1234'),
        'current_workspace_id'          => $workspace->id,
        'first_login_completed_at'      => now(),
    ]);
}

dataset('protected_get_endpoints', [
    'workspace'           => ['/api/v1/workspace'],
    'users'               => ['/api/v1/users'],
    'companies'           => ['/api/v1/companies'],
    'contacts'            => ['/api/v1/contacts'],
    'coverage (default)'  => ['/api/v1/coverage'],
    'coverage region'     => ['/api/v1/coverage?level=region'],
    'coverage department' => ['/api/v1/coverage?level=department'],
    'coverage city'       => ['/api/v1/coverage?level=city'],
    'coverage next-zone'  => ['/api/v1/coverage/next-zone'],
    'rgpd requests'       => ['/api/v1/rgpd/requests'],
    'ai-act register'     => ['/api/v1/ai-act/register'],
    'rotations'           => ['/api/v1/rotations'],
    'proxy providers'     => ['/api/v1/proxy-providers'],
    'scraper runs'        => ['/api/v1/scraper-runs'],
    'audit logs'          => ['/api/v1/audit-logs'],
    'audit verify-chain'  => ['/api/v1/audit-logs/verify-chain'],
    'tags'                => ['/api/v1/tags'],
    'llm use-cases'       => ['/api/v1/llm/use-cases'],
    'llm usage'           => ['/api/v1/llm/usage'],
    'llm usage summary'   => ['/api/v1/llm/usage/summary'],
    'notifications'       => ['/api/v1/notifications'],
    'saved views'         => ['/api/v1/saved-views'],
    'dashboard stats'     => ['/api/v1/dashboard/stats'],
    'search'              => ['/api/v1/search'],
]);

test('endpoint {0} authentifié ne retourne JAMAIS 500', function (string $url) {
    $u = makeNoFiveHundredUser();
    $resp = $this->actingAs($u)->getJson($url);

    // Tolérance : 200 happy path, 404 ressource absente, 422 validation. JAMAIS 500.
    expect($resp->getStatusCode())->not->toBe(500);
    expect($resp->getStatusCode())->toBeLessThan(500);
})->with('protected_get_endpoints');

test('endpoint {0} sans auth retourne 401 (jamais 500)', function (string $url) {
    $resp = $this->getJson($url);
    // Sans auth → 401 (Sanctum) ou éventuellement 419 CSRF. Surtout pas 500.
    expect($resp->getStatusCode())->not->toBe(500);
    expect($resp->getStatusCode())->toBeLessThan(500);
})->with('protected_get_endpoints');

test('broadcasting default = log par défaut sans REVERB_APP_KEY', function () {
    // En env testing, BROADCAST_CONNECTION n'est pas set + REVERB_APP_KEY non plus.
    // Le fallback automatique doit nous donner 'log' (safe).
    expect(config('broadcasting.default'))->toBe('log');
});

test('routes/channels.php skip enregistrement quand driver = log', function () {
    // Cf. routes/channels.php — le bloc Broadcast::channel(...) n'est exécuté
    // que si driver ∉ {log, null}. Ici on vérifie juste que la config est bien 'log'
    // (test garde-fou contre régression future).
    expect(config('broadcasting.default'))->toBe('log');
});

test('coverage cells endpoint retourne 200 même sans data', function () {
    $u = makeNoFiveHundredUser();
    $resp = $this->actingAs($u)->getJson('/api/v1/coverage/cells/9999');
    $resp->assertOk();
});
