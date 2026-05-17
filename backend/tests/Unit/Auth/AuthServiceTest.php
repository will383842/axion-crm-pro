<?php

use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\AuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function authUser(string $email, string $password = 'OkPassword!1234', array $extra = []): User
{
    $workspace = Workspace::create([
        'id' => (string) Str::uuid(),
        'slug' => 'auth-' . Str::random(6),
        'name' => 'Auth WS',
    ]);
    return User::create(array_merge([
        'id' => (string) Str::uuid(),
        'email' => $email,
        'name' => 'Auth Test',
        'password_hash' => Hash::make($password),
        'current_workspace_id' => $workspace->id,
    ], $extra));
}

test('AuthService::attemptLogin avec bons credentials retourne user + requires_2fa', function () {
    $user = authUser('auth1@test.local');
    $service = app(AuthService::class);
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);

    $result = $service->attemptLogin($request, 'auth1@test.local', 'OkPassword!1234');
    expect($result)
        ->toHaveKey('user')
        ->toHaveKey('requires_2fa');
    expect($result['requires_2fa'])->toBeFalse();
});

test('AuthService::attemptLogin avec totp_enabled_at retourne requires_2fa=true', function () {
    $user = authUser('auth2@test.local', 'OkPassword!1234', [
        'totp_enabled_at' => now(),
    ]);
    $service = app(AuthService::class);
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);

    $result = $service->attemptLogin($request, 'auth2@test.local', 'OkPassword!1234');
    expect($result['requires_2fa'])->toBeTrue();
});

test('AuthService::attemptLogin avec mauvais password lance ValidationException', function () {
    authUser('auth3@test.local');
    $service = app(AuthService::class);
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '10.20.30.40']);

    expect(fn () => $service->attemptLogin($request, 'auth3@test.local', 'WrongPassword!'))
        ->toThrow(ValidationException::class);
});

test('AuthService::attemptLogin reset failed_login_count après login OK', function () {
    $user = authUser('auth4@test.local', 'OkPassword!1234', [
        'failed_login_count' => 3,
    ]);
    $service = app(AuthService::class);
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);

    $service->attemptLogin($request, 'auth4@test.local', 'OkPassword!1234');
    expect($user->fresh()->failed_login_count)->toBe(0);
});

test('AuthService::attemptLogin rejette account avec locked_until future', function () {
    $user = authUser('auth5@test.local', 'OkPassword!1234', [
        'locked_until' => now()->addHours(2),
    ]);
    $service = app(AuthService::class);
    $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '127.0.0.1']);

    expect(fn () => $service->attemptLogin($request, 'auth5@test.local', 'OkPassword!1234'))
        ->toThrow(ValidationException::class);
});

test('AuthService::attemptLogin trace last_login_ip + user_agent', function () {
    $user = authUser('auth6@test.local');
    $service = app(AuthService::class);
    $request = Request::create('/login', 'POST', server: [
        'REMOTE_ADDR' => '203.0.113.42',
        'HTTP_USER_AGENT' => 'Test/1.0',
    ]);

    $service->attemptLogin($request, 'auth6@test.local', 'OkPassword!1234');
    $fresh = $user->fresh();
    expect($fresh->last_login_ip)->toBe('203.0.113.42');
    expect($fresh->last_login_user_agent)->toContain('Test/1.0');
});

test('AuthService constants exposed', function () {
    expect(AuthService::MAX_FAILED_ATTEMPTS)->toBe(10);
    expect(AuthService::LOCK_DURATION_SECONDS)->toBe(86400);
});
