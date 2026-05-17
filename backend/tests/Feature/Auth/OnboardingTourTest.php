<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function tourUser(): User
{
    $workspace = Workspace::create([
        'id'    => (string) Str::uuid(),
        'slug'  => 'tour-ws-' . Str::random(6),
        'name'  => 'Tour WS',
    ]);
    return User::create([
        'id'                            => (string) Str::uuid(),
        'email'                         => 'tour' . Str::random(4) . '@test.local',
        'name'                          => 'Tour User',
        'password_hash'                 => Hash::make('SomePassword123!'),
        'current_workspace_id'          => $workspace->id,
        'first_login_completed_at'      => now(),  // bypass 2FA enforcement
        'onboarding_tour_completed_at'  => null,
    ]);
}

test('POST /auth/onboarding/complete sans auth → 401', function () {
    $this->postJson('/api/v1/auth/onboarding/complete')->assertUnauthorized();
});

test('POST /auth/onboarding/complete authentifié pose onboarding_tour_completed_at', function () {
    $user = tourUser();
    $this->actingAs($user)
        ->postJson('/api/v1/auth/onboarding/complete')
        ->assertOk()
        ->assertJsonPath('onboarding_tour_completed_at', fn ($v) => is_string($v) && strlen($v) > 10);

    expect($user->fresh()->onboarding_tour_completed_at)->not->toBeNull();
});

test('POST /auth/onboarding/complete idempotent ne change pas la date', function () {
    $user = tourUser();
    $this->actingAs($user)->postJson('/api/v1/auth/onboarding/complete')->assertOk();
    $firstTimestamp = $user->fresh()->onboarding_tour_completed_at;

    sleep(1);

    $this->actingAs($user)->postJson('/api/v1/auth/onboarding/complete')->assertOk();
    $secondTimestamp = $user->fresh()->onboarding_tour_completed_at;

    expect($secondTimestamp->toIso8601String())->toBe($firstTimestamp->toIso8601String());
});

test('GET /auth/me expose onboarding_tour_completed_at', function () {
    $user = tourUser();
    $this->actingAs($user)
        ->getJson('/api/v1/auth/me')
        ->assertOk()
        ->assertJsonPath('user.onboarding_tour_completed_at', null);
});
