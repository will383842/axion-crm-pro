<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeCoverageUser(): array
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'cov-' . Str::random(6),
        'name' => 'Cov WS',
    ]);
    $user = User::create([
        'id' => (string) Str::uuid(),
        'email' => 'cov' . Str::random(4) . '@test.local',
        'name' => 'Cov',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
    return [$user, $workspace];
}

test('GET /coverage sans auth → 401', function () {
    $this->getJson('/api/v1/coverage')->assertUnauthorized();
});

test('GET /coverage authentifié → OK avec cells', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->getJson('/api/v1/coverage')
        ->assertOk()
        ->assertJsonStructure(['cells']);
});

test('GET /coverage?level=region authentifié → OK', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->getJson('/api/v1/coverage?level=region')
        ->assertOk();
});

test('GET /coverage?level=city authentifié → OK', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->getJson('/api/v1/coverage?level=city')
        ->assertOk();
});

test('GET /coverage/next-zone authentifié → OK', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->getJson('/api/v1/coverage/next-zone')
        ->assertOk()
        ->assertJsonStructure(['zone']);
});

test('POST /coverage/launch valide le département', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->postJson('/api/v1/coverage/launch', [])
        ->assertStatus(422);
});

test('POST /coverage/launch accepte body valide', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->postJson('/api/v1/coverage/launch', [
            'department' => '75',
            'limit'      => 50,
        ])
        ->assertOk()
        ->assertJsonStructure(['queued']);
});

test('POST /coverage/launch refuse limit > 1000', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->postJson('/api/v1/coverage/launch', [
            'department' => '75',
            'limit'      => 5000,
        ])
        ->assertStatus(422);
});

test('GET /coverage/cells/42 retourne le détail cell', function () {
    [$u] = makeCoverageUser();
    $this->actingAs($u)
        ->getJson('/api/v1/coverage/cells/42')
        ->assertOk()
        ->assertJson(['id' => 42]);
});
