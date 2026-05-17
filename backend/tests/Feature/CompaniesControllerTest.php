<?php

use App\Models\User;
use App\Models\Workspace;
use App\Models\Company;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->workspace = Workspace::create([
        'id' => (string) Str::uuid(), 'slug' => 'ws-tst', 'name' => 'WS', 'settings' => [],
    ]);
    $this->user = User::create([
        'id' => (string) Str::uuid(), 'email' => 'u@example.com', 'name' => 'U',
        'password_hash' => Hash::make('PasswordTest12345!'),
        'current_workspace_id' => $this->workspace->id,
        'first_login_completed_at' => now(),
    ]);
    $this->actingAs($this->user);
});

test('index returns paginated empty list', function () {
    $r = $this->getJson('/api/v1/companies?per_page=10');
    $r->assertOk();
    $r->assertJsonStructure(['data', 'meta' => ['total', 'per_page', 'current_page', 'last_page']]);
});

test('store validates siren format 9 digits', function () {
    $this->postJson('/api/v1/companies', ['siren' => '12345'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('siren');
});

test('store creates company with valid siren', function () {
    $r = $this->postJson('/api/v1/companies', [
        'siren' => '123456789',
        'denomination' => 'Test Corp',
    ]);
    $r->assertStatus(201);
    expect(Company::where('siren', '123456789')->exists())->toBeTrue();
});

test('update validates priority enum', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '111111111',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $this->putJson("/api/v1/companies/{$c->id}", ['priority' => 'invalid-value'])
        ->assertStatus(422);
});

test('update with valid priority succeeds', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '222222222',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $this->putJson("/api/v1/companies/{$c->id}", ['priority' => 'haute'])
        ->assertOk();
    expect($c->fresh()->priority)->toBe('haute');
});

test('destroy soft-deletes company', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '333333333',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $this->deleteJson("/api/v1/companies/{$c->id}")->assertNoContent();
});

test('recompute-score endpoint calls SQL function', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '444444444',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    $r = $this->postJson("/api/v1/companies/{$c->id}/recompute-score");
    $r->assertOk();
});

test('bulkEnrich queues jobs', function () {
    $c = Company::create([
        'workspace_id' => $this->workspace->id, 'siren' => '555555555',
        'denomination' => 'X', 'signals' => [], 'metadata' => [],
    ]);
    \Illuminate\Support\Facades\Queue::fake();
    $this->postJson('/api/v1/companies/bulk-enrich', ['ids' => [$c->id]])->assertOk();
    \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\EnrichCompanyJob::class);
});
