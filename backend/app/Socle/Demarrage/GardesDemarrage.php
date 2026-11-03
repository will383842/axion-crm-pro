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

    /**
     * @param  array<array-key, mixed>  $catalogue
     * @param  array<array-key, mixed>  $liste
     */
    private static function failoverContientLog(array $catalogue, array $liste): bool
    {
        foreach ($liste as $nom) {
            if ($nom === 'log' || (($catalogue[$nom]['transport'] ?? null) === 'log')) {
                return true;
            }
        }

        return false;
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

        // Un transport `log` écrirait le lien magique / de réinitialisation EN CLAIR
        // dans les journaux (valable 15 min) : refusé en production.
        if ($transport === 'log') {
            throw new RuntimeException("Démarrage refusé : le mailer d'authentification écrirait les liens dans les journaux (MAIL_MAILER_AUTH).");
        }

        // `failover` retombe sur le mailer suivant : s'il contient `log`, même fuite.
        if ($transport === 'failover' && self::failoverContientLog($catalogue, (array) ($catalogue[$auth]['mailers'] ?? []))) {
            throw new RuntimeException("Démarrage refusé : le mailer d'authentification de secours peut retomber sur l'écriture dans les journaux (MAIL_MAILER_AUTH).");
        }

        // Le simulateur général neutralise l'envoi d'authentification : refusé quel que soit le transport.
        if (config('crm.mock_mode')) {
            throw new RuntimeException('Démarrage refusé : le simulateur MOCK_MODE est allumé.');
        }

        $envoiReel = ! in_array($transport, self::TRANSPORTS_SANS_ENVOI, true);

        // MOCK_MODE est déjà refusé plus haut : il reste MOCK_MAIL.
        if ($envoiReel && config('crm.mock_mail')) {
            throw new RuntimeException("Démarrage refusé : un simulateur (MOCK_MODE, MOCK_MAIL) est allumé alors que l'envoi réel l'est.");
        }
    }
}
