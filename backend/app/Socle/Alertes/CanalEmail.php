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
     * Courriel TRANSACTIONNEL à UNE adresse (celle d'un compte connecté), par le même transport que les alertes : jamais par le
     * moteur d'envoi ni par une boîte d'envoi. Ne casse jamais son appelant : `false` en cas de panne ou d'adresse invalide.
     */
    public function envoyerA(string $adresse, string $objet, string $corps): bool
    {
        if (filter_var($adresse, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            Mail::to($adresse)->send(new CourrielTransactionnel($objet, $corps));

            return true;
        } catch (\Throwable $e) {
            Log::error('Courriel transactionnel impossible.', ['exception' => $e::class]);

            return false;
        }
    }
}
