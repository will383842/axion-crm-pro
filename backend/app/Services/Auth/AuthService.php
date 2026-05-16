<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Service orchestrant le login Sanctum SPA :
 * - throttle 5/min par IP (RateLimiter "login")
 * - vérifie credentials + locked_until (24h auto-lock après 10 fails)
 * - démarre session web (cookie HttpOnly + Secure + SameSite=lax)
 * - retourne `{user, requires_2fa}` ; 2FA forcé si totp_enabled_at non null
 */
class AuthService
{
    public const MAX_FAILED_ATTEMPTS = 10;
    public const LOCK_DURATION_SECONDS = 86400; // 24h

    /**
     * @return array{user: User, requires_2fa: bool}
     */
    public function attemptLogin(Request $request, string $email, string $password, bool $remember = false): array
    {
        $throttleKey = "login:{$request->ip()}:" . strtolower($email);
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($throttleKey)]),
            ]);
        }

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->whereNull('deleted_at')->first();

        if (! $user || ! $user->password_hash || ! Hash::check($password, $user->password_hash)) {
            RateLimiter::hit($throttleKey, 60);
            if ($user) {
                $user->failed_login_count = ($user->failed_login_count ?? 0) + 1;
                if ($user->failed_login_count >= self::MAX_FAILED_ATTEMPTS) {
                    $user->locked_until = now()->addSeconds(self::LOCK_DURATION_SECONDS);
                }
                $user->save();
            }
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        if ($user->locked_until && $user->locked_until->isFuture()) {
            throw ValidationException::withMessages([
                'email' => __('auth.locked', ['until' => $user->locked_until->toIso8601String()]),
            ]);
        }

        RateLimiter::clear($throttleKey);
        $user->forceFill([
            'failed_login_count'    => 0,
            'locked_until'          => null,
            'last_login_at'         => now(),
            'last_login_ip'         => $request->ip(),
            'last_login_user_agent' => substr((string) $request->userAgent(), 0, 255),
        ])->save();

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return [
            'user'         => $user->fresh(),
            'requires_2fa' => $user->totp_enabled_at !== null,
        ];
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
