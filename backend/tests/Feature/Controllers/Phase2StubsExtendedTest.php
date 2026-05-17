<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makePhase2User(): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'p2-' . Str::random(6),
        'name' => 'P2 WS',
    ]);
    return User::create([
        'id' => (string) Str::uuid(),
        'email' => 'p2-' . Str::random(4) . '@test.local',
        'name' => 'P2',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
}

test('Phase2 stubs sans auth retournent 401', function () {
    $this->getJson('/api/v1/campaigns')->assertUnauthorized();
    $this->getJson('/api/v1/cold-email')->assertUnauthorized();
    $this->getJson('/api/v1/linkedin')->assertUnauthorized();
    $this->getJson('/api/v1/crm')->assertUnauthorized();
    $this->getJson('/api/v1/analytics')->assertUnauthorized();
});

test('Phase2 campaigns avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/campaigns')->assertStatus(501);
});

test('Phase2 cold-email avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/cold-email')->assertStatus(501);
});

test('Phase2 linkedin avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/linkedin')->assertStatus(501);
});

test('Phase2 crm avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/crm')->assertStatus(501);
});

test('Phase2 analytics avec auth → 501', function () {
    $u = makePhase2User();
    $this->actingAs($u)->getJson('/api/v1/analytics')->assertStatus(501);
});

test('Phase2 response shape inclus sprint metadata', function () {
    $u = makePhase2User();
    $resp = $this->actingAs($u)->getJson('/api/v1/campaigns');
    $resp->assertStatus(501)->assertJsonStructure(['error', 'message', 'sprint']);
});
