<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige que la double authentification ait RÉELLEMENT été franchie.
 *
 * 🔴 POURQUOI CE FICHIER EXISTE. `TwoFactorController::verify()` posait
 * `2fa_passed_at` dans la session — et **rien, nulle part, ne le relisait**
 * (recherche exhaustive sur `app/`, `routes/`, `tests/`, `config/` et
 * `frontend/src` : une seule occurrence, celle qui écrit). La bascule vers
 * l'écran `/2fa` était purement côté navigateur (`LoginPage.tsx`) : qui
 * possédait le mot de passe possédait le CRM, 2FA activée ou non.
 * Mesuré le 2026-08-19 (audit 360, F35-003).
 *
 * Un drapeau de session que personne ne relit ne protège rien.
 *
 * Contrat :
 * - compte sans 2FA (`totp_enabled_at` nul) → laissé passer, rien à exiger ;
 * - compte avec 2FA et session portant un `2fa_passed_at` encore valide → passe ;
 * - sinon → **403 `two_factor_required`**, avec l'étape suivante.
 *
 * La liste blanche est celle qui permet, précisément, de franchir l'étape : se
 * regarder, se déconnecter, et vérifier son code.
 */
class EnsureTwoFactorPassed
{
    /** Durée de validité d'un franchissement de 2FA, en secondes. */
    public const VALIDITY_SECONDS = 43200; // 12 h

    /** @var list<string> */
    private const ALLOW_LIST = [
        'api/v1/auth/me',
        'api/v1/auth/logout',
        'api/v1/auth/2fa/setup',
        'api/v1/auth/2fa/confirm',
        'api/v1/auth/2fa/verify',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->totp_enabled_at === null) {
            return $next($request);
        }

        if (in_array($request->path(), self::ALLOW_LIST, true)) {
            return $next($request);
        }

        if ($this->franchie($request)) {
            return $next($request);
        }

        return response()->json([
            'error' => 'two_factor_required',
            'message' => 'Saisissez le code de votre application d\'authentification pour continuer.',
            'next_step' => '/auth/2fa/verify',
        ], 403);
    }

    private function franchie(Request $request): bool
    {
        if (! $request->hasSession()) {
            // Requête par jeton d'API : pas de session, donc pas de 2FA de session.
            // Le jeton est un secret porteur, déjà soumis à `auth:sanctum`.
            return $request->user()?->currentAccessToken() !== null;
        }

        $passeA = $request->session()->get('2fa_passed_at');
        if (! is_string($passeA) || $passeA === '') {
            return false;
        }

        try {
            $quand = Carbon::parse($passeA);
        } catch (\Throwable) {
            return false;
        }

        return $quand->addSeconds(self::VALIDITY_SECONDS)->isFuture();
    }
}
