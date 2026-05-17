<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeAuthUser(): array
{
    $workspace = Workspace::create([
        'id'    => (string) Str::uuid(),
        'slug'  => 'ws-' . Str::random(6),
        'name'  => 'Test WS',
    ]);
    $user = User::create([
        'id'                            => (string) Str::uuid(),
        'email'                         => 'user' . Str::random(4) . '@test.local',
        'name'                          => 'Test User',
        'password_hash'                 => Hash::make('SomePass!1234'),
        'current_workspace_id'          => $workspace->id,
        'first_login_completed_at'      => now(),
    ]);
    return [$user, $workspace];
}

test('GET /workspace sans auth → 401', function () {
    $this->getJson('/api/v1/workspace')->assertUnauthorized();
});

test('GET /workspace authentifié retourne le workspace courant', function () {
    [$user, $workspace] = makeAuthUser();
    $this->actingAs($user)
        ->getJson('/api/v1/workspace')
        ->assertOk();
});

test('PUT /workspace retourne 501 (Sprint 3 stub)', function () {
    [$user] = makeAuthUser();
    $this->actingAs($user)
        ->putJson('/api/v1/workspace', ['name' => 'New'])
        ->assertStatus(501);
});

test('GET /users sans auth → 401', function () {
    $this->getJson('/api/v1/users')->assertUnauthorized();
});

test('GET /users authentifié retourne liste vide stub', function () {
    [$user] = makeAuthUser();
    $this->actingAs($user)
        ->getJson('/api/v1/users')
        ->assertOk()
        ->assertJsonStructure(['data']);
});

test('POST /users retourne 501', function () {
    [$user] = makeAuthUser();
    $this->actingAs($user)
        ->postJson('/api/v1/users', ['email' => 'a@b.com'])
        ->assertStatus(501);
});
