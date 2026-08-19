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

        if ($user === null) {
            // Adresse inconnue : on ne revele rien a l'appelant (la reponse du
            // controleur est identique dans les deux cas), et surtout ON N'ECRIT
            // RIEN.
            //
            // Une ligne etait inseree meme sans compte, avec `user_id` a NULL. Or
            // `consume()` retrouvait l'utilisateur PAR ADRESSE : un lien emis pour
            // une adresse sans compte devenait utilisable des que le compte etait
            // cree, dans les 15 minutes. Quiconque connaissait l'adresse d'un futur
            // collaborateur pouvait ainsi preparer un lien et prendre sa session.
            // Accessoirement, la table grossissait sans borne (3 insertions par
            // minute et par IP, aucune purge) en stockant adresse et IP du
            // demandeur - donnees personnelles. Mesure (audit 360, F35-013).
            \Log::info('Lien magique demande pour une adresse inconnue', ['email' => $email]);

            return;
        }

        $token = Str::random(64);
        $tokenHash = hash('sha256', $token);

        DB::table('magic_links')->insert([
            'id'         => (string) Str::uuid(),
            'user_id'    => $user->id,
            'email'      => $email,
            'token_hash' => $tokenHash,
            'ip'         => $ip,
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'created_at' => now(),
        ]);

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

        // On resout par `user_id`, jamais par adresse : un lien vaut pour LE compte
        // auquel il a ete emis, pas pour le compte qui portera cette adresse plus
        // tard (F35-013).
        if ($row->user_id === null) {
            return null;
        }

        return User::query()->where('id', $row->user_id)->whereNull('deleted_at')->first();
    }
}
