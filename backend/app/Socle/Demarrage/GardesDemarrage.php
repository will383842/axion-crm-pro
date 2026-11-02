<?php

namespace App\Socle\Demarrage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Gardes de démarrage du courrier d'authentification (S2, ADR-08).
 *
 * En production, le démarrage est REFUSÉ si le courrier d'authentification est
 * mal réglé. Les messages de refus ne citent JAMAIS une valeur de configuration.
 *
 * Retour arrière : `GARDES_DEMARRAGE_AUTH` absent ou à false (défaut) = aucune garde.
 */
class GardesDemarrage extends ServiceProvider
{
    /** Transports qui n'envoient rien de réel. */
    private const TRANSPORTS_SANS_ENVOI = ['log', 'array'];

    public function boot(): void
    {
        self::verifier($this->app);
    }

    public static function verifier(Application $app): void
    {
        if (! $app->environment('production') || ! config('mail.gardes_demarrage', false)) {
            return;
        }

        $auth = config('mail.auth_mailer');
        $catalogue = (array) config('mail.mailers', []);

        if (! is_string($auth) || $auth === '' || ! isset($catalogue[$auth])) {
            throw new RuntimeException("Démarrage refusé : le mailer d'authentification est absent ou inconnu (MAIL_MAILER_AUTH).");
        }

        if ($auth === config('mail.default')) {
            throw new RuntimeException("Démarrage refusé : le mailer d'authentification est identique au mailer par défaut (MAIL_MAILER_AUTH).");
        }

        $transport = (string) ($catalogue[$auth]['transport'] ?? '');
        $envoiReel = ! in_array($transport, self::TRANSPORTS_SANS_ENVOI, true);

        if ($envoiReel && (config('crm.mock_mode') || config('crm.mock_mail'))) {
            throw new RuntimeException("Démarrage refusé : un simulateur (MOCK_MODE, MOCK_MAIL) est allumé alors que l'envoi réel l'est.");
        }
    }
}
