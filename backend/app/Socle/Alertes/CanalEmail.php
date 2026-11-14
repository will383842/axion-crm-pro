<?php

namespace App\Socle\Alertes;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/** Canal e-mail : adresse d'alerte principale + seconde adresse (hors domaine Axion). */
final class CanalEmail
{
    /** @return list<string> */
    public static function adresses(): array
    {
        $liste = [
            (string) config('alertes.email.destinataire', ''),
            (string) config('alertes.email.seconde_adresse', ''),
        ];

        return array_values(array_unique(array_filter(
            array_map(static fn (string $a): string => strtolower(trim($a)), $liste),
            static fn (string $a): bool => $a !== '' && filter_var($a, FILTER_VALIDATE_EMAIL) !== false,
        )));
    }

    /** Ne casse jamais son appelant : une panne d'envoi est journalisée et rendue en `false`. */
    public function envoyer(string $titre, string $corps, ?string $lien): bool
    {
        $adresses = self::adresses();
        if ($adresses === []) {
            Log::warning('Alerte par e-mail NON CONFIGUREE : poser ALERTES_EMAIL_DESTINATAIRE (et, de préférence, ALERTES_EMAIL_SECONDE_ADRESSE).');

            return false;
        }

        try {
            Mail::to($adresses)->send(new AlerteMail($titre, $corps, $lien));

            return true;
        } catch (\Throwable $e) {
            Log::error('Alerte par e-mail impossible.', ['exception' => $e::class]);

            return false;
        }
    }

    /**
     * Vrai si le transport EFFECTIF du mailer d'authentification (`mail.auth_mailer`, MAIL_MAILER_AUTH) peut porter un secret :
     * jamais `log` (le secret serait écrit en clair dans les journaux), ni un `failover` qui contient `log`, ni `array`
     * (le message se perd) hors environnement de test. Mailer absent ou inconnu : faux.
     */
    public static function transportSur(): bool
    {
        $nom = config('mail.auth_mailer');
        $catalogue = (array) config('mail.mailers', []);
        if (! is_string($nom) || $nom === '' || ! isset($catalogue[$nom])) {
            return false;
        }

        return self::transportSans($catalogue, $nom, []);
    }

    /**
     * @param  array<string, mixed>  $catalogue
     * @param  list<string>  $vus  mailers déjà parcourus (un secours qui se cite lui-même ne boucle pas)
     */
    private static function transportSans(array $catalogue, string $nom, array $vus): bool
    {
        if (in_array($nom, $vus, true)) {
            return true;
        }
        $transport = (string) ($catalogue[$nom]['transport'] ?? '');
        if ($transport === 'log' || $transport === '' || ($transport === 'array' && ! app()->environment('testing'))) {
            return false;
        }
        if ($transport === 'failover' || $transport === 'roundrobin') {
            foreach ((array) ($catalogue[$nom]['mailers'] ?? []) as $suivant) {
                if (! is_string($suivant) || ! isset($catalogue[$suivant]) || ! self::transportSans($catalogue, $suivant, [...$vus, $nom])) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Courriel TRANSACTIONNEL à UNE adresse (celle d'un compte connecté), pouvant porter un SECRET (ex. code de validation) :
     * il part par le mailer d'AUTHENTIFICATION, jamais par le mailer par défaut (qui vaut `log` en production), et jamais si ce
     * transport écrirait le message dans un journal. Jamais par le moteur d'envoi ni par une boîte d'envoi.
     * Ne casse jamais son appelant : `false` en cas de panne, d'adresse invalide ou de transport peu sûr.
     */
    public function envoyerA(string $adresse, string $objet, string $corps): bool
    {
        if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false || ! self::transportSur()) {
            return false;
        }

        try {
            Mail::mailer((string) config('mail.auth_mailer'))->to($adresse)->send(new CourrielTransactionnel($objet, $corps));

            return true;
        } catch (\Throwable $e) {
            Log::error('Courriel transactionnel impossible.', ['exception' => $e::class]);

            return false;
        }
    }
}
