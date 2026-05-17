<?php

use App\Models\ScraperRun;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeScraperUser(string $slug = 'sr'): array
{
    $workspace = Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => $slug . '-' . Str::random(6),
        'name' => 'WS ' . $slug,
    ]);
    $user = User::create([
        'id'                       => (string) Str::uuid(),
        'email'                    => $slug . Str::random(4) . '@test.local',
        'name'                     => 'User ' . $slug,
        'password_hash'            => Hash::make('SomePass!1234'),
        'current_workspace_id'     => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
    return [$user, $workspace];
}

function makeScraperRun(Workspace $workspace, string $status = 'pending', array $extra = []): ScraperRun
{
    return ScraperRun::create(array_merge([
        'workspace_id'    => $workspace->id,
        'source'          => 'insee',
        'status'          => $status,
        'started_at'      => now(),
        'request_payload' => ['type' => 'zone-launch', 'department' => '75', 'limit' => 50],
    ], $extra));
}

test('cancel — run pending → 200 + status=cancelled', function () {
    [$u, $w] = makeScraperUser();
    $run = makeScraperRun($w, 'pending');

    $r = $this->actingAs($u)->postJson("/api/v1/scraper-runs/{$run->id}/cancel");

    $r->assertOk();
    expect($run->fresh()->status)->toBe('cancelled');
    expect($run->fresh()->finished_at)->not->toBeNull();
});

test('cancel — run running → 200 + status=cancelled', function () {
    [$u, $w] = makeScraperUser();
    $run = makeScraperRun($w, 'running');

    $this->actingAs($u)
        ->postJson("/api/v1/scraper-runs/{$run->id}/cancel")
        ->assertOk();

    expect($run->fresh()->status)->toBe('cancelled');
});

test('cancel — run success → 422 invalid_state', function () {
    [$u, $w] = makeScraperUser();
    $run = makeScraperRun($w, 'success');

    $this->actingAs($u)
        ->postJson("/api/v1/scraper-runs/{$run->id}/cancel")
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_state');
});

test('cancel — cross-workspace → 404 (pas 403, pas de leak)', function () {
    [$u1, $w1] = makeScraperUser('ws1');
    [, $w2]    = makeScraperUser('ws2');
    $run = makeScraperRun($w2, 'pending'); // run dans ws2

    $this->actingAs($u1) // user de ws1
        ->postJson("/api/v1/scraper-runs/{$run->id}/cancel")
        ->assertNotFound();

    expect($run->fresh()->status)->toBe('pending'); // pas modifié
});

test('retry — run failed → 201 + nouveau run pending', function () {
    [$u, $w] = makeScraperUser();
    $run = makeScraperRun($w, 'failed');

    Queue::fake();

    $r = $this->actingAs($u)->postJson("/api/v1/scraper-runs/{$run->id}/retry");

    $r->assertStatus(201);
    $newId = (int) $r->json('id');
    expect($newId)->toBeGreaterThan(0);
    expect(ScraperRun::where('id', $newId)->where('status', 'pending')->exists())->toBeTrue();
    Queue::assertPushed(\App\Jobs\LaunchZoneScrapingJob::class);
});

test('retry — run cancelled → 201 + nouveau run pending', function () {
    [$u, $w] = makeScraperUser();
    $run = makeScraperRun($w, 'cancelled');

    Queue::fake();

    $this->actingAs($u)
        ->postJson("/api/v1/scraper-runs/{$run->id}/retry")
        ->assertStatus(201);

    expect(ScraperRun::where('workspace_id', $w->id)->where('status', 'pending')->count())->toBe(1);
});

test('retry — run running → 422 invalid_state', function () {
    [$u, $w] = makeScraperUser();
    $run = makeScraperRun($w, 'running');

    $this->actingAs($u)
        ->postJson("/api/v1/scraper-runs/{$run->id}/retry")
        ->assertStatus(422)
        ->assertJsonPath('error', 'invalid_state');
});

test('retry — cross-workspace → 404', function () {
    [$u1] = makeScraperUser('ws1');
    [, $w2] = makeScraperUser('ws2');
    $run = makeScraperRun($w2, 'failed');

    $this->actingAs($u1)
        ->postJson("/api/v1/scraper-runs/{$run->id}/retry")
        ->assertNotFound();
});
