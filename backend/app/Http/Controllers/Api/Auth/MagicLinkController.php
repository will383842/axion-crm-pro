<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Services\Auth\MagicLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class MagicLinkController extends ApiController
{
    public function __construct(private readonly MagicLinkService $service) {}

    /**
     * @OA\Post(path="/auth/magic-link", tags={"Auth"}, summary="Demande un magic-link (anti-enum, 3/IP/10min)",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"email"},
     *
     *         @OA\Property(property="email", type="string", format="email"))),
     *
     *     @OA\Response(response=200, description="Lien envoyé (toujours)"))
     */
    public function request(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email', 'max:254']]);

        $key = "magic-link:{$request->ip()}";
        if (RateLimiter::tooManyAttempts($key, 3)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }
        RateLimiter::hit($key, 600);

        $this->service->issue((string) $request->input('email'), $request->ip());

        // Réponse identique que l'email existe ou non (anti enumération).
        return $this->ok(['sent' => true]);
    }

    /**
     * @OA\Post(path="/auth/magic-link/verify", tags={"Auth"}, summary="Consomme le token magic-link (one-shot)",
     *
     *     @OA\RequestBody(required=true, @OA\JsonContent(required={"token"},
     *
     *         @OA\Property(property="token", type="string", maxLength=64))),
     *
     *     @OA\Response(response=200, description="Loggé"),
     *     @OA\Response(response=401, description="Token invalide ou expiré"))
     */
    public function verify(Request $request): JsonResponse
    {
        $request->validate(['token' => ['required', 'string', 'size:64']]);

        $user = $this->service->consume((string) $request->input('token'));
        if (! $user) {
            return response()->json(['error' => 'invalid_or_expired_token'], 401);
        }

        // 🔴 « SE SOUVENIR » — constat prod du 2026-10-02. `Auth::login($user)`
        // sans second argument : la session mourait avec `session.lifetime`, et
        // le propriétaire, qui n'entrait plus QUE par lien, devait redemander un
        // courriel toutes les deux heures. Le lien prouve la possession de la
        // boîte aux lettres : c'est au moins aussi fort qu'un mot de passe, qui
        // obtient « se souvenir » d'une simple case cochée.
        Auth::login($user, true);
        $request->session()->regenerate();

        // Même trace qu'une connexion par mot de passe (`AuthService::attemptLogin`).
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_login_user_agent' => substr((string) $request->userAgent(), 0, 255),
        ])->save();

        // Pendant 30 minutes, cette session peut changer le mot de passe sans
        // l'ancien (cf. `PasswordChangeController`).
        PasswordChangeController::marquerSessionParLien($request);

        return $this->ok([
            'user' => $user->only(['id', 'email', 'name', 'current_workspace_id', 'totp_enabled_at']),
            'requires_2fa' => $user->totp_enabled_at !== null,
        ]);
    }
}
