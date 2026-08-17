<?php

/**
 * Amorçage de la suite de tests — ÉPINGLE LA BASE DE DONNÉES.
 *
 * 🔴 Pourquoi ce fichier existe (constaté le 2026-08-17, E2E n°2).
 *
 * `docker-compose.yml` déclare `env_file: .env` pour le service `api`, et ce
 * `.env` porte `DB_DATABASE=axion_crm` — la base de DÉVELOPPEMENT. Lancer la
 * suite dans ce conteneur, c'est-à-dire exactement ce que documente
 * `make test-backend` (`docker exec axion-crm-api composer test`), faisait donc
 * viser cette base, et `RefreshDatabase` y lançait
 * `DROP TABLE … CASCADE` sur une centaine de tables.
 *
 * Le `<env name="DB_DATABASE" … force="true"/>` de `phpunit.xml` NE SUFFIT PAS :
 * PHPUnit écrit alors `$_ENV` et `putenv()`, **mais pas `$_SERVER`**. Or le
 * dépôt de variables de Laravel interroge `ServerConstAdapter` (`$_SERVER`) en
 * premier, et `variables_order = EGPCS` y place les variables du conteneur.
 * Mesuré : `getenv='axion_crm' | $_ENV='axion_crm' | $_SERVER='axion_crm'`, et
 * la suite continuait de viser `axion_crm` malgré `force="true"`.
 *
 * On épingle donc la valeur dans LES TROIS emplacements, avant tout démarrage
 * de l'application. Une garde d'exécution (`Tests\TestCase::setUp()`) vérifie
 * ensuite que la connexion réelle porte bien une base de test : ce fichier
 * décide, la garde constate.
 */
const TEST_DATABASE_NAME = 'axion_crm_test';

foreach (['DB_DATABASE' => TEST_DATABASE_NAME, 'DB_CONNECTION' => 'pgsql'] as $cle => $valeur) {
    $_SERVER[$cle] = $valeur;
    $_ENV[$cle] = $valeur;
    putenv("{$cle}={$valeur}");
}

require __DIR__ . '/../vendor/autoload.php';
