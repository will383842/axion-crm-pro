<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Magic Link (passwordless) :
 * - utilise table `magic_links` (token_hash sha256, expires_at 15 min)
 * - lien plain envoyé par email — jamais stocké côté serveur
 * - one-shot (consumed_at non null après usage)
 */
class MagicLinkService
{
    public const TTL_MINUTES = 15;

    public function issue(string $email, ?string $ip = null): void
    {
        $user = User::query()->where('email', $email)->whereNull('deleted_at')->first();
        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        DB::table('magic_links')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user?->id,
            'email'      => $email,
            'token_hash' => $tokenHash,
            'ip'         => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);

        if ($user === null) {
            // Email inconnu : on logue mais on ne révèle pas (email enumeration prevention).
            \Log::info('Magic link requested for unknown email', ['email' => $email]);
            return;
        }

        $link = config('app.frontend_url', 'https://app.localhost') . '/magic-link/verify?token=' . $token;

        if (env('MOCK_MODE', true)) {
            \Log::info('Mock magic link (would be emailed)', ['email' => $email, 'link' => $link]);
            return;
        }

        Mail::raw("Connexion à Axion CRM Pro :\n\n{$link}\n\nLien valable 15 minutes, à usage unique.", function ($m) use ($email) {
            $m->to($email)->subject('Lien de connexion Axion CRM Pro');
        });
    }

    public function consume(string $token): ?User
    {
        $tokenHash = hash('sha256', $token);
        $row = DB::table('magic_links')
            ->where('token_hash', $tokenHash)
            ->whereNull('consumed_at')
            ->where('expires_at', '>', now())
            ->first();

        if (! $row) {
            return null;
        }

        DB::table('magic_links')->where('id', $row->id)->update(['consumed_at' => now()]);

        return User::query()->where('email', $row->email)->whereNull('deleted_at')->first();
    }
}
