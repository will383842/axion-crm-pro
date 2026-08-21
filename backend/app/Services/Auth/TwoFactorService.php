<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;

/**
 * TOTP RFC 6238 via pragmarx/google2fa.
 *
 * 🔴 LES NOMS DE COLONNES. Ce service écrivait `two_factor_secret`,
 * `two_factor_enabled` et `two_factor_recovery_codes`. Ces trois colonnes
 * n'existent dans AUCUNE migration : la table `users` porte `totp_secret`,
 * `totp_enabled_at` et `totp_recovery_codes` (migration 000002, §1.2).
 * L'enrôlement partait donc en `SQLSTATE[42703] undefined column` — et comme
 * `confirmEnrolment()` est le SEUL endroit qui pose `first_login_completed_at`,
 * `EnforceFirstLoginSetup` verrouillait ensuite le produit entier : aucun compte
 * neuf ne pouvait franchir sa première connexion. Mesuré le 2026-08-19
 * (audit 360, A07-001 / F35-002).
 *
 * On aligne le CODE sur le SCHÉMA, et non l'inverse : `totp_*` est déjà lu par
 * `AuthService` (`totp_enabled_at` décide de `requires_2fa`) et par
 * `infra/scripts/definir-mot-de-passe-crm.sh`. Créer `two_factor_*` aurait donné
 * deux jeux de colonnes pour la même chose, donc une divergence garantie.
 *
 * - `totp_secret` est chiffré au repos (cast `encrypted` côté User)
 * - 10 codes de secours hachés bcrypt (cast `encrypted:array`)
 * - « la 2FA est active » se lit sur `totp_enabled_at`, il n'y a pas de drapeau
 *   booléen séparé à tenir synchronisé.
 */
class TwoFactorService
{
    /** Nombre de pas de 30 s tolérés de part et d'autre de l'horloge serveur. */
    public const TOTP_WINDOW = 1;

    public const RECOVERY_CODE_COUNT = 10;

    private Google2FA $g2fa;

    public function __construct()
    {
        $this->g2fa = new Google2FA;
    }

    /**
     * @return array{secret: string, qr_url: string}
     */
    public function startEnrolment(User $user): array
    {
        $secret = $this->g2fa->generateSecretKey();
        $user->totp_secret = $secret;
        $user->save();

        $companyName = (string) config('app.name', 'Axion CRM Pro');
        $qrCodeUrl = $this->g2fa->getQRCodeUrl(
            $companyName,
            $user->email,
            $secret,
        );

        return ['secret' => $secret, 'qr_url' => $qrCodeUrl];
    }

    /**
     * @return list<string> codes de secours, à montrer une seule fois puis perdus
     */
    public function confirmEnrolment(User $user, string $oneTimeCode): array
    {
        $secret = $user->totp_secret;
        if (! $secret) {
            throw ValidationException::withMessages(['code' => "L'enrôlement 2FA n'a pas été commencé."]);
        }

        if (! $this->g2fa->verifyKey($secret, $oneTimeCode, self::TOTP_WINDOW)) {
            throw ValidationException::withMessages(['code' => 'Code TOTP invalide.']);
        }

        $recoveryCodes = [];
        $hashed = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            // 🔴 PAS de strtoupper() ici. `Str::random()` tire dans [A-Za-z0-9]
            // (62 signes) ; replier en majuscules ramenait l'alphabet à 36 et
            // faisait perdre 8 bits par code, sans rien apporter en lisibilité
            // puisque la comparaison se fait par `Hash::check`, sensible à la casse.
            $code = Str::random(10);
            $recoveryCodes[] = $code;
            $hashed[] = Hash::make($code);
        }

        $user->forceFill([
            'totp_enabled_at' => now(),
            'totp_recovery_codes' => $hashed,
            'first_login_completed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    /**
     * Vérifie un code TOTP, ou l'un des codes de secours (à usage unique).
     */
    public function verify(User $user, string $code): bool
    {
        $secret = $user->totp_secret;
        if (! $secret) {
            return false;
        }

        if ($this->g2fa->verifyKey((string) $secret, $code, self::TOTP_WINDOW)) {
            return true;
        }

        // Codes de secours : consommés au premier usage.
        $codes = $user->totp_recovery_codes ?? [];
        foreach ($codes as $i => $hashed) {
            if (Hash::check($code, $hashed)) {
                $restants = array_values(array_filter(
                    $codes,
                    fn ($_, $idx) => $idx !== $i,
                    ARRAY_FILTER_USE_BOTH,
                ));
                $user->totp_recovery_codes = $restants;
                $user->save();

                return true;
            }
        }

        return false;
    }
}
