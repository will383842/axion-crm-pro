<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeSearchUser(): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'srch-' . Str::random(6),
        'name' => 'Search WS',
    ]);
    return User::create([
        'id' => (string) Str::uuid(),
        'email' => 'srch' . Str::random(4) . '@test.local',
        'name' => 'Search',
        'password_hash' => Hash::make('SomePass!1234'),
        'current_workspace_id' => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
}

test('GET /search sans auth → 401', function () {
    $this->getJson('/api/v1/search')->assertUnauthorized();
});

test('GET /search authentifié → OK structure groups', function () {
    $u = makeSearchUser();
    $this->actingAs($u)
        ->getJson('/api/v1/search?q=test')
        ->assertOk()
        ->assertJsonStructure(['companies', 'contacts', 'tags']);
});

test('GET /dashboard/stats sans auth → 401', function () {
    $this->getJson('/api/v1/dashboard/stats')->assertUnauthorized();
});

test('GET /dashboard/stats authentifié → OK keys', function () {
    $u = makeSearchUser();
    $resp = $this->actingAs($u)->getJson('/api/v1/dashboard/stats')->assertOk();
    $resp->assertJsonStructure([
        'companies_total',
        'companies_enriched_24h',
        'contacts_qualified',
        'scraper_runs_24h',
        'llm_cost_eur_month',
        'quality_distribution',
        'size_distribution',
    ]);
});

test('GET /dashboard/stats expose 5 quality buckets', function () {
    $u = makeSearchUser();
    $resp = $this->actingAs($u)->getJson('/api/v1/dashboard/stats')->assertOk();
    $resp->assertJsonStructure([
        'quality_distribution' => ['complete', 'partielle', 'basique'],
    ]);
});

test('GET /dashboard/stats expose 5 size buckets', function () {
    $u = makeSearchUser();
    $resp = $this->actingAs($u)->getJson('/api/v1/dashboard/stats')->assertOk();
    $resp->assertJsonStructure([
        'size_distribution' => ['artisan', 'tpe', 'pme', 'eti', 'grande_entreprise'],
    ]);
});
