<?php

use App\Models\RgpdRequest;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRgpdUser(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'rgpd-' . Str::random(6),
        'name' => 'RGPD WS',
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'rgpd' . Str::random(4) . '@test.local',
        'name' => 'RGPD',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
    return [$user, $workspace];
}

test('GET /rgpd/requests sans auth → 401', function () {
    $this->getJson('/api/v1/rgpd/requests')->assertUnauthorized();
});

test('GET /rgpd/requests authentifié → OK', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)->getJson('/api/v1/rgpd/requests')->assertOk();
});

test('POST /rgpd/requests crée une demande', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'erasure',
            'subject_email' => 'subject@example.com',
        ])
        ->assertStatus(201)
        ->assertJsonStructure(['id', 'type', 'status', 'subject_email']);
});

test('POST /rgpd/requests valide le type', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'invalid_type',
            'subject_email' => 'subject@example.com',
        ])
        ->assertStatus(422);
});

test('POST /rgpd/requests valide email', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->postJson('/api/v1/rgpd/requests', [
            'type' => 'access',
            'subject_email' => 'not-an-email',
        ])
        ->assertStatus(422);
});

test('POST /rgpd/requests accepte les 5 types valides', function () {
    [$u] = makeRgpdUser();
    foreach (['access', 'portability', 'erasure', 'rectification', 'opposition'] as $type) {
        $this->actingAs($u)
            ->postJson('/api/v1/rgpd/requests', [
                'type' => $type,
                'subject_email' => "$type@example.com",
            ])
            ->assertStatus(201);
    }
});

test('GET /rgpd/export/{token} avec token invalide → 404', function () {
    $this->getJson('/api/v1/rgpd/export/invalid_token_123')->assertNotFound();
});

test('GET /audit-logs/verify-chain authentifié → OK', function () {
    [$u] = makeRgpdUser();
    $this->actingAs($u)
        ->getJson('/api/v1/audit-logs/verify-chain')
        ->assertOk()
        ->assertJsonStructure(['valid']);
});
