<?php

namespace App\Services\Auth\CodeCourriel;

use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Vrai canal : courrier TRANSACTIONNEL (même transport dédié que le lien magique), sans lien dans le corps. */
final class CanalCodeConnexionCourriel implements CanalCodeConnexion
{
    public function envoyer(User $u, string $code, string $objet): void
    {
        $minutes = (int) config('double_auth.duree_code_minutes', 10);
        $adresse = (string) $u->email;

        Mail::mailer(config('mail.auth_mailer'))->raw(
            "Votre code de connexion Axion CRM Pro : {$code}\n\nCe code est valable {$minutes} minutes et ne sert qu'une fois. "
            . "Si vous n'êtes pas à l'origine de cette demande, ignorez ce message.",
            function ($m) use ($adresse) {
                $m->to($adresse)->subject('Votre code de connexion');
            },
        );
    }

    public function estReel(): bool
    {
        return true;
    }
}
