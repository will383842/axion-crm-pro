<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class PasswordResetController extends ApiController
{
    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:254']]);

        $key = "password-reset:{$request->ip()}";
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
        RateLimiter::hit($key, 900);

        $email = (string) $request->input('email');
        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => hash('sha256', $token), 'created_at' => now()],
        );

        if (env('MOCK_MODE', true)) {
            \Log::info('Mock password reset link (would be emailed)', [
                'email' => $email,
                'link'  => config('app.frontend_url') . '/password-reset?token=' . $token . '&email=' . urlencode($email),
            ]);
        } else {
            $link = config('app.frontend_url') . '/password-reset?token=' . $token . '&email=' . urlencode($email);
            Mail::raw("Réinitialisez votre mot de passe :\n\n{$link}\n\nValide 60 minutes.", function ($m) use ($email) {
                $m->to($email)->subject('Réinitialisation du mot de passe — Axion CRM Pro');
            });
        }

        return $this->ok(['sent' => true]);
    }

    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => ['required', 'email'],
            'token'    => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', Password::min(12)->uncompromised()],
        ]);

        $email = (string) $request->input('email');
        $tokenHash = hash('sha256', (string) $request->input('token'));

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $row || ! hash_equals((string) $row->token, $tokenHash)) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        // TTL 60 minutes
        if ($row->created_at && now()->diffInMinutes($row->created_at) > 60) {
            return response()->json(['error' => 'expired_token'], 401);
        }

        $user = User::query()->where('email', $email)->whereNull('deleted_at')->first();
        if (! $user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        $user->password_hash = Hash::make((string) $request->input('password'));
        $user->failed_login_count = 0;
        $user->locked_until = null;
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        return $this->ok(['reset' => true]);
    }
}
