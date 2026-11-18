<?php

namespace App\Socle\Demarrage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * G4-A — refus de démarrer en production sans le rôle applicatif (garde sœur de `GardesDemarrage`).
 *
 * Tant que l'application tourne sous le rôle propriétaire, la sécurité par ligne est contournée.
 * Quand la garde est allumée (`CRM_GARDE_ROLE_APPLICATIF`, éteinte par défaut), la production refuse de démarrer
 * si le rôle applicatif n'est pas en place. Trois contrôles, sur la configuration seule :
 * aucune requête vers la base au démarrage (une base lente ferait tomber toutes les commandes).
 * Les messages ne citent JAMAIS une valeur.
 *
 * Seule exception : `migrate --database=pgsql_owner` passe toujours, pour qu'un mauvais réglage ne bloque pas sa propre réparation.
 *
 * Retour arrière : `CRM_GARDE_ROLE_APPLICATIF=false` (défaut) = aucune garde.
 */
class GardeRoleApplicatif extends ServiceProvider
{
    public function boot(): void
    {
        self::verifier($this->app);
    }

    public static function verifier(Application $app): void
    {
        if (! $app->environment('production') || ! config('crm.garde_role_applicatif', false)) {
            return;
        }

        if (self::migrationSousProprietaire($app)) {
            return;
        }

        if (! config('crm.db_app_role')) {
            throw new RuntimeException("Démarrage refusé : le rôle applicatif n'est pas activé (CRM_DB_APP_ROLE_ENABLED).");
        }

        $connexion = (string) config('database.connections.pgsql.username');
        $proprietaire = (string) config('database.connections.pgsql_owner.username');
        if ($connexion === $proprietaire) {
            throw new RuntimeException("Démarrage refusé : la connexion de l'application est celle du propriétaire (DB_APP_USERNAME).");
        }

        if ((string) config('database.connections.pgsql_app.password') === '') {
            throw new RuntimeException('Démarrage refusé : mot de passe du rôle applicatif absent (DB_APP_PASSWORD).');
        }
    }

    /** Vrai pour `artisan migrate --database=pgsql_owner` seulement. */
    private static function migrationSousProprietaire(Application $app): bool
    {
        if (! $app->runningInConsole()) {
            return false;
        }

        $argv = (array) ($_SERVER['argv'] ?? []);

        return ($argv[1] ?? null) === 'migrate' && in_array('--database=pgsql_owner', $argv, true);
    }
}
