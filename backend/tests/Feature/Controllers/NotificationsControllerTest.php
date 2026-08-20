<?php

use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\PermissionsAndRolesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeNotifUser(): User
{
    $workspace = Workspace::create([
        'id'    => (string) Str::uuid(),
        'slug'  => 'notif-ws-' . Str::random(6),
        'name'  => 'Notif WS',
    ]);
    return User::create([
        'id'                            => (string) Str::uuid(),
        'email'                         => 'notif' . Str::random(4) . '@test.local',
        'name'                          => 'Notif User',
        'password_hash'                 => Hash::make('SomePass!1234'),
        'current_workspace_id'          => $workspace->id,
        'first_login_completed_at'      => now(),
    ]);
}

/**
 * Le meme compte, mais porteur d'un role -- constat P5-35-006.
 *
 * `makeNotifUser()` ne pose AUCUN role. C'etait sans consequence tant qu'aucune
 * route n'exigeait de permission ; le commit 46848d4 a pose `audit.view` sur
 * GET /api/v1/audit-logs, et ce fichier s'est mis a rougir sans avoir ete
 * touche par la branche.
 *
 * Ce rouge est LA PREUVE QUE LA GARDE MARCHE. La pente naturelle serait de
 * relacher la route pour reverdir le test : ce serait rouvrir la fuite que le
 * correctif vient de fermer. On donne donc un role au test.
 *
 * Spatie tourne en mode « teams » : le role est attribue PAR espace de travail,
 * d'ou `setPermissionsTeamId()` avant `assignRole()`.
 */
function makeNotifUserAvecRole(string $role = 'admin'): User
{
    $u = makeNotifUser();
    setPermissionsTeamId($u->current_workspace_id);
    $u->assignRole($role);

    return $u;
}

test('GET /notifications sans auth → 401', function () {
    $this->getJson('/api/v1/notifications')->assertUnauthorized();
});

test('GET /notifications authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/notifications')->assertOk()->assertJsonStructure(['data']);
});

test('POST /notifications/1/read retourne 501', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->postJson('/api/v1/notifications/1/read')->assertStatus(501);
});

test('POST /notifications/read-all retourne 501', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->postJson('/api/v1/notifications/read-all')->assertStatus(501);
});

test('GET /saved-views authentifié → OK liste vide', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/saved-views')->assertOk();
});

test('GET /tags authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/tags')->assertOk();
});

test('GET /audit-logs avec la permission audit.view -> OK', function () {
    $this->seed(PermissionsAndRolesSeeder::class);
    $u = makeNotifUserAvecRole('admin');
    $this->actingAs($u)->getJson('/api/v1/audit-logs')->assertOk();
});

test('P5-35-006 -- GET /audit-logs SANS la permission audit.view -> 403', function () {
    // Le pendant du test ci-dessus, et la raison pour laquelle on ne relache
    // pas la route : un compte authentifie mais sans `audit.view` ne doit pas
    // atteindre le journal d'audit. Sans cette assertion, un futur relachement
    // de la route reverdirait ce fichier sans que personne ne le voie passer.
    $this->seed(PermissionsAndRolesSeeder::class);
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/audit-logs')->assertForbidden();
});

test('GET /llm/use-cases authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/llm/use-cases')->assertOk();
});

test('GET /llm/usage authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/llm/usage')->assertOk();
});

test('GET /llm/usage/summary authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/llm/usage/summary')->assertOk();
});

test('GET /proxy-providers authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/proxy-providers')->assertOk();
});

test('GET /rotations authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/rotations')->assertOk();
});

test('GET /ai-act/register authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/ai-act/register')->assertOk();
});

test('GET /scraper-runs authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/scraper-runs')->assertOk();
});

test('GET /contacts authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/contacts')->assertOk();
});
