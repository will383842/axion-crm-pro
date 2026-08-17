<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * GARDE — la suite ne tourne JAMAIS contre autre chose qu'une base de test.
     *
     * `RefreshDatabase` commence par `DROP TABLE … CASCADE` sur toutes les
     * tables de la connexion. Si celle-ci pointe la base de développement (ou
     * pire), le premier test la vide. Le 2026-08-17, seul le hasard d'un rôle
     * non-propriétaire l'a empêché ; avec la configuration par défaut le rôle
     * est SUPERUSER et le DROP réussit.
     *
     * `tests/bootstrap.php` épingle le nom. Cette garde CONSTATE le résultat au
     * bon moment — sur la connexion réellement établie, pas sur ce qu'on
     * croyait avoir configuré. Elle échoue fort, avec le nom fautif en clair.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) $this->app['db']->connection()->getDatabaseName();

        // Laravel suffixe la base par processus en exécution parallèle
        // (`axion_crm_test_test_1`) : on valide le PRÉFIXE, pas l'égalité.
        if (! str_starts_with($base, TEST_DATABASE_NAME)) {
            throw new RuntimeException(
                'REFUS DE DÉMARRER : la suite est connectée à « ' . $base . " », qui n'est pas une base de test."
                . ' Le premier test lancerait `DROP TABLE … CASCADE` dessus. Attendu : un nom commençant par « '
                . TEST_DATABASE_NAME . ' ». Cause la plus probable : une variable DB_DATABASE héritée de'
                . " l'environnement du conteneur (`env_file: .env` dans docker-compose.yml) — cf. tests/bootstrap.php.",
            );
        }
    }
}
