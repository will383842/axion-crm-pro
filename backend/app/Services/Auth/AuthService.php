<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Service orchestrant la connexion Sanctum SPA :
 * - plafond 5/min par IP+adresse (RateLimiter "login"), en plus du `throttle:login` de la route
 * - vérifie le verrou de compte, PUIS les identifiants
 * - démarre la session web (cookie HttpOnly + Secure + SameSite=lax)
 * - retourne `{user, requires_2fa}` ; la 2FA est exigée si `totp_enabled_at` est non nul
 */
class AuthService
{
    public const MAX_FAILED_ATTEMPTS = 10;

    public const LOCK_DURATION_SECONDS = 86400; // 24 h

    /**
     * Au-delà de ce silence, les échecs précédents sont oubliés.
     *
     * 🔴 Sans fenêtre d'oubli, `failed_login_count` ne redescendait JAMAIS autrement
     * que par une connexion réussie ou une réinitialisation. Dix fautes de frappe
     * étalées sur six mois verrouillaient donc un compte légitime pour 24 h, et la
     * cause était introuvable. Mesuré le 2026-08-19 (audit 360, F35-012).
     */
    public const FAILURE_MEMORY_SECONDS = 1800; // 30 min

    /**
     * Hachage bcrypt d'une valeur qui n'est le mot de passe de personne.
     *
     * 🔴 ÉNUMÉRATION DE COMPTES PAR LE TEMPS. `Hash::check()` n'était appelé que si
     * l'utilisateur existait : une adresse inconnue répondait sans travail
     * cryptographique, une adresse connue payait un bcrypt de coût 12. Les corps de
     * réponse étaient identiques, les durées ne l'étaient pas — et un écart de temps
     * est une énumération aussi. On paie donc toujours le même prix.
     * Mesuré le 2026-08-19 (audit 360, F35-009).
     */
    /** @var array<string, string> hachage factice, mémorisé PAR COÛT bcrypt */
    private static array $hachagesFactices = [];

    /**
     * Un hachage valide, du MÊME coût que ceux de la base, qui n'est le mot de
     * passe de personne.
     *
     * Figer une constante en dur donnerait un coût différent de
     * `hashing.bcrypt.rounds` et déplacerait simplement l'écart de temps au lieu
     * de le supprimer. On mémorise donc par coût : calculer le hachage à chaque
     * tentative doublerait le travail, mais le mémoriser sans tenir compte du
     * coût rendrait la mesure fausse dès que la configuration change.
     */
    private function hachageFactice(): string
    {
        $cout = (string) config('hashing.bcrypt.rounds', 12);

        return self::$hachagesFactices[$cout] ??= Hash::make('aucun-compte-ne-porte-ce-mot-de-passe');
    }

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

        // ── 1. Le verrou d'abord. ────────────────────────────────────────────
        // Il était vérifié APRÈS `Hash::check()` : un compte verrouillé continuait
        // donc de payer un bcrypt par tentative (levier d'épuisement CPU) et son
        // compteur d'échecs continuait de monter. Le verrou doit couper avant.
        if ($user && $user->locked_until && $user->locked_until->isFuture()) {
            RateLimiter::hit($throttleKey, 60);
            throw ValidationException::withMessages([
                'email' => __('auth.locked', ['until' => $user->locked_until->toIso8601String()]),
            ]);
        }

        // Verrou expiré : on repart de zéro, sans quoi la 11ᵉ tentative reverrouille
        // aussitôt le compte que les 24 h viennent de libérer.
        if ($user && $user->locked_until && $user->locked_until->isPast()) {
            $user->forceFill(['locked_until' => null, 'failed_login_count' => 0])->save();
        }

        // ── 2. Le mot de passe, au même coût pour tout le monde. ─────────────
        $hachage = ($user && $user->password_hash) ? $user->password_hash : $this->hachageFactice();
        $motDePasseValide = Hash::check($password, $hachage);

        if (! $user || ! $user->password_hash || ! $motDePasseValide) {
            RateLimiter::hit($throttleKey, 60);
            if ($user) {
                $this->enregistrerEchec($user);
            }
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // ── 3. Succès. ───────────────────────────────────────────────────────
        RateLimiter::clear($throttleKey);
        $user->forceFill([
            'failed_login_count' => 0,
            'last_failed_login_at' => null,
            'locked_until' => null,
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_login_user_agent' => substr((string) $request->userAgent(), 0, 255),
        ])->save();

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return [
            'user' => $user->fresh(),
            'requires_2fa' => $user->totp_enabled_at !== null,
        ];
    }

    /**
     * Incrémente le compteur d'échecs, en oubliant ceux qui sont trop anciens,
     * et verrouille le compte au seuil.
     */
    private function enregistrerEchec(User $user): void
    {
        $dernier = $user->last_failed_login_at;
        $oublie = $dernier === null
            || $dernier->lt(now()->subSeconds(self::FAILURE_MEMORY_SECONDS));

        $compte = $oublie ? 1 : ((int) ($user->failed_login_count ?? 0)) + 1;

        $user->failed_login_count = $compte;
        $user->last_failed_login_at = now();

        if ($compte >= self::MAX_FAILED_ATTEMPTS) {
            $user->locked_until = now()->addSeconds(self::LOCK_DURATION_SECONDS);
        }

        $user->save();
    }

    public function logout(Request $request): void
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }
}
