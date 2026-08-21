<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\User;
use App\Rules\NotPwnedPassword;
use Carbon\Carbon;
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
    /** Duree de vie d'un jeton de reinitialisation, en minutes. */
    public const TOKEN_TTL_MINUTES = 60;

    /**
     * @OA\Post(path="/auth/password/forgot", tags={"Auth"}, summary="Envoie email reset password (anti-enum, throttled)",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"email"},
     *
     *         @OA\Property(property="email", type="string", format="email"))),
     *
     *     @OA\Response(response=200, description="Envoyé (toujours)"))
     */
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

        // `config()`, jamais `env()` : avec `config:cache` - que l'entrypoint de
        // production tente a chaque demarrage - le `.env` n'est plus lu, et
        // `env('MOCK_MODE', true)` rendait alors TRUE en production. Le courriel
        // n'etait donc jamais envoye, meme avec un SMTP configure (F40-002).
        if (config('crm.mock_mode', true)) {
            \Log::info('Mock password reset link (would be emailed)', [
                'email' => $email,
                'link' => config('app.frontend_url') . '/password-reset?token=' . $token . '&email=' . urlencode($email),
            ]);
        } else {
            $link = config('app.frontend_url') . '/password-reset?token=' . $token . '&email=' . urlencode($email);
            // Transport DEDIE a l'authentification (cf. config/mail.php).
            Mail::mailer(config('mail.auth_mailer'))->raw("Réinitialisez votre mot de passe :\n\n{$link}\n\nValide 60 minutes.", function ($m) use ($email) {
                $m->to($email)->subject('Réinitialisation du mot de passe — Axion CRM Pro');
            });
        }

        return $this->ok(['sent' => true]);
    }

    /**
     * @OA\Post(path="/auth/password/reset", tags={"Auth"}, summary="Reset password via token (vérif HIBP)",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"email","token","password"},
     *
     *         @OA\Property(property="email", type="string", format="email"),
     *         @OA\Property(property="token", type="string", maxLength=64),
     *         @OA\Property(property="password", type="string", minLength=12),
     *         @OA\Property(property="password_confirmation", type="string"))),
     *
     *     @OA\Response(response=200, description="Reset OK"),
     *     @OA\Response(response=401, description="Token invalide/expiré"),
     *     @OA\Response(response=422, description="Password compromis (HIBP) ou validation"))
     */
    public function reset(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'confirmed', Password::min(12), new NotPwnedPassword],
        ]);

        $email = (string) $request->input('email');
        $tokenHash = hash('sha256', (string) $request->input('token'));

        $row = DB::table('password_reset_tokens')->where('email', $email)->first();
        if (! $row || ! hash_equals((string) $row->token, $tokenHash)) {
            return response()->json(['error' => 'invalid_token'], 401);
        }

        // -- Expiration : 60 minutes -----------------------------------------
        // Le test precedent etait `now()->diffInMinutes($row->created_at) > 60`.
        // Depuis Carbon 3, `diffIn*()` rend une valeur SIGNEE (`absolute` valait
        // `true` par defaut en Carbon 2, plus depuis). `now()` etant a gauche et la
        // creation dans le passe, le resultat etait TOUJOURS negatif : la
        // comparaison etait toujours fausse et le jeton n'expirait JAMAIS.
        // Mesure : -179,99 pour 3 h, -43 199,98 pour 30 jours (audit 360, F35-005).
        // On compare desormais une date a maintenant, ce qui n'a pas de signe.
        $creeA = $row->created_at ? Carbon::parse($row->created_at) : null;
        if ($creeA === null || $creeA->addMinutes(self::TOKEN_TTL_MINUTES)->isPast()) {
            return response()->json(['error' => 'expired_token'], 401);
        }

        $user = User::query()->where('email', $email)->whereNull('deleted_at')->first();
        if (! $user) {
            return response()->json(['error' => 'user_not_found'], 404);
        }

        $user->password_hash = Hash::make((string) $request->input('password'));
        $user->failed_login_count = 0;
        $user->last_failed_login_at = null;
        $user->locked_until = null;
        $user->save();

        DB::table('password_reset_tokens')->where('email', $email)->delete();

        // -- Couper tous les acces ouverts avec l'ANCIEN mot de passe ---------
        // La reinitialisation ne revoquait rien. C'est pourtant le geste que fait
        // un utilisateur PARCE QU'IL SE CROIT COMPROMIS : il doit fermer la porte.
        // Les sessions web etaient deja couvertes par `AuthenticateSession`
        // (Sanctum compare le hachage du mot de passe stocke en session) ; les
        // jetons d'API, eux, survivaient indefiniment - d'autant que
        // `sanctum.expiration` valait `null`. Mesure (audit 360, F35-006).
        $user->tokens()->delete();

        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }

        return $this->ok(['reset' => true]);
    }
}
