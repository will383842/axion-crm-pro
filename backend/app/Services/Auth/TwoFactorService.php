<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use PragmaRX\Google2FA\Google2FA;
use Illuminate\Support\Str;

/**
 * TOTP RFC 6238 via pragmarx/google2fa.
 * - le `totp_secret` est chiffré au repos (cast `encrypted` côté User)
 * - 10 recovery codes hashés bcrypt (cast `encrypted:array`)
 * - une fois 2FA activé : `totp_enabled_at` non null → middleware EnforceFirstLoginSetup laisse passer
 */
class TwoFactorService
{
    private Google2FA $g2fa;

    public function __construct()
    {
        $this->g2fa = new Google2FA();
    }

    public function startEnrolment(User $user): array
    {
        $secret = $this->g2fa->generateSecretKey();
        $user->two_factor_secret = $secret;
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
     * @return list<string> recovery codes (à montrer une fois à l'utilisateur, puis perdus)
     */
    public function confirmEnrolment(User $user, string $oneTimeCode): array
    {
        $secret = $user->two_factor_secret;
        if (! $secret) {
            throw ValidationException::withMessages(['code' => '2FA enrolment not started']);
        }

        if (! $this->g2fa->verifyKey($secret, $oneTimeCode, 1)) {
            throw ValidationException::withMessages(['code' => 'Code TOTP invalide.']);
        }

        $recoveryCodes = [];
        $hashed = [];
        for ($i = 0; $i < 10; $i++) {
            $code = strtoupper(Str::random(10));
            $recoveryCodes[] = $code;
            $hashed[] = Hash::make($code);
        }

        $user->forceFill([
            'two_factor_enabled'       => true,
            'totp_enabled_at'          => now(),
            'two_factor_recovery_codes'=> $hashed,
            'first_login_completed_at' => now(),
        ])->save();

        return $recoveryCodes;
    }

    public function verify(User $user, string $code): bool
    {
        if (! $user->totp_secret && ! $user->two_factor_secret) {
            return false;
        }
        $secret = $user->two_factor_secret ?? $user->totp_secret;

        if ($this->g2fa->verifyKey((string) $secret, $code, 1)) {
            return true;
        }

        // Recovery codes (cast encrypted:array)
        $codes = $user->two_factor_recovery_codes ?? [];
        foreach ($codes as $i => $hashed) {
            if (Hash::check($code, $hashed)) {
                $remaining = array_values(array_filter($codes, fn ($_, $idx) => $idx !== $i, ARRAY_FILTER_USE_BOTH));
                $user->two_factor_recovery_codes = $remaining;
                $user->save();
                return true;
            }
        }
        return false;
    }
}
