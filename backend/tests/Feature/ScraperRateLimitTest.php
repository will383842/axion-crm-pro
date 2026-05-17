<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeRateLimitUser(): User
{
    $workspace = Workspace::create([
        'id'   => (string) Str::uuid(),
        'slug' => 'rl-' . Str::random(6),
        'name' => 'RL WS',
    ]);
    return User::create([
        'id'                       => (string) Str::uuid(),
        'email'                    => 'rl' . Str::random(4) . '@test.local',
        'name'                     => 'RL User',
        'password_hash'            => Hash::make('SomePass!1234'),
        'current_workspace_id'     => $workspace->id,
        'first_login_completed_at' => now(),
    ]);
}

beforeEach(function () {
    RateLimiter::clear('scraper-launch');
    RateLimiter::clear('scraper-list');
});

test('coverage/launch — 11ème requête en moins d\'1min → 429', function () {
    $user = makeRateLimitUser();
    $this->actingAs($user);

    // 10 premières dans la limite
    for ($i = 1; $i <= 10; $i++) {
        $r = $this->postJson('/api/v1/coverage/launch', [
            'department' => '75',
            'limit'      => 10,
        ]);
        // 200 (queued) ou 422 (validation) acceptés ; on vérifie juste != 429.
        expect($r->getStatusCode())->not->toBe(429, "Requête #{$i} ne doit pas être rate-limited");
    }

    // 11ème → 429
    $r = $this->postJson('/api/v1/coverage/launch', [
        'department' => '75',
        'limit'      => 10,
    ]);
    $r->assertStatus(429);
    expect($r->headers->has('Retry-After'))->toBeTrue();
});

test('scraper-launch limiter applique aussi à cancel + retry', function () {
    $user = makeRateLimitUser();
    $this->actingAs($user);

    // Consomme 10 sur le bucket scraper-launch via /coverage/launch
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/api/v1/coverage/launch', ['department' => '75', 'limit' => 10]);
    }

    // cancel sur run inexistant : doit toucher le rate limiter avant le 404
    $this->postJson('/api/v1/scraper-runs/999999/cancel')->assertStatus(429);
    $this->postJson('/api/v1/scraper-runs/999999/retry')->assertStatus(429);
});

test('scraper-list limiter : 61ème GET /scraper-runs en moins d\'1min → 429', function () {
    $user = makeRateLimitUser();
    $this->actingAs($user);

    for ($i = 1; $i <= 60; $i++) {
        $r = $this->getJson('/api/v1/scraper-runs');
        expect($r->getStatusCode())->not->toBe(429, "Requête list #{$i} ne doit pas être rate-limited");
    }

    $this->getJson('/api/v1/scraper-runs')->assertStatus(429);
});

test('rate limiter par-user : user A consume, user B pas affecté', function () {
    $userA = makeRateLimitUser();
    $userB = makeRateLimitUser();

    // user A consume tout son quota scraper-launch
    $this->actingAs($userA);
    for ($i = 1; $i <= 10; $i++) {
        $this->postJson('/api/v1/coverage/launch', ['department' => '75', 'limit' => 10]);
    }
    $this->postJson('/api/v1/coverage/launch', ['department' => '75', 'limit' => 10])->assertStatus(429);

    // user B doit pouvoir faire ses requêtes
    $this->actingAs($userB);
    $r = $this->postJson('/api/v1/coverage/launch', ['department' => '75', 'limit' => 10]);
    expect($r->getStatusCode())->not->toBe(429);
});
