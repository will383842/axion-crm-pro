<?php

use App\Models\User;
use App\Models\Workspace;
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

test('GET /audit-logs authentifié → OK', function () {
    $u = makeNotifUser();
    $this->actingAs($u)->getJson('/api/v1/audit-logs')->assertOk();
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
