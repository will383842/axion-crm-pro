<?php

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function makeUser(string $email = 'user@example.com', string $password = 'CorrectPassword12345!'): User
{
    $workspace = Workspace::create([
        'id'    => (string) Str::uuid(),
        'slug'  => 'test-ws-' . Str::random(6),
        'name'  => 'Test WS',
    ]);
    return User::create([
        'id'                   => (string) Str::uuid(),
        'email'                => $email,
        'name'                 => 'Test User',
        'password_hash'        => Hash::make($password),
        'current_workspace_id' => $workspace->id,
    ]);
}

test('login with correct credentials succeeds', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'CorrectPassword12345!',
    ])->assertOk()->assertJsonStructure(['user', 'requires_2fa']);
});

test('login with wrong password fails 422', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'WrongPassword999!',
    ])->assertStatus(422);
});

test('login increments failed_login_count on wrong password', function () {
    $user = makeUser();
    $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrongPasswordOK1!'])->assertStatus(422);
    expect($user->fresh()->failed_login_count)->toBe(1);
});

test('login locks user after 10 failed attempts', function () {
    $user = makeUser();
    for ($i = 0; $i < 10; $i++) {
        $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => "wrong{$i}attempt12!"]);
    }
    expect($user->fresh()->locked_until)->not->toBeNull();
});

test('login rejects locked account even with correct password', function () {
    $user = makeUser();
    $user->locked_until = now()->addHours(24);
    $user->save();

    $this->postJson('/api/v1/auth/login', [
        'email'    => $user->email,
        'password' => 'CorrectPassword12345!',
    ])->assertStatus(422);
});

test('login validates password min length', function () {
    $this->postJson('/api/v1/auth/login', ['email' => 'test@test.com', 'password' => 'short'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('password');
});

test('login throttles after 5 attempts per minute', function () {
    for ($i = 0; $i < 6; $i++) {
        $resp = $this->postJson('/api/v1/auth/login', [
            'email'    => 'spam@test.com',
            'password' => 'IncorrectPassword12!',
        ]);
        if ($i >= 5) {
            $resp->assertStatus(429);
            return;
        }
    }
});

test('me endpoint returns 401 when not authenticated', function () {
    $this->getJson('/api/v1/auth/me')->assertUnauthorized();
});
