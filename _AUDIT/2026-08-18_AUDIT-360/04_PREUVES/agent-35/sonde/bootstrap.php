<?php

/**
 * Amorçage AUDIT AGENT 35 — copie de tests/bootstrap.php, base dédiée.
 * Le préfixe reste `axion_crm_test` pour satisfaire la garde Tests\TestCase.
 */
const TEST_DATABASE_NAME = 'axion_crm_test';

// APP_ENV doit AUSSI être épinglé dans $_SERVER : le conteneur pose APP_ENV=local
// via `docker run -e`, et `variables_order = EGPCS` le place dans $_SERVER, que
// `<env force="true"/>` de PHPUnit n'écrit pas (cf. tests/bootstrap.php). Sans
// cela la suite tourne en env `local`, où `ValidateCsrfToken` ne s'auto-désactive
// pas : toute requête POST stateful part en 419 et l'on mesure le CSRF, pas l'auth.
foreach ([
    'DB_DATABASE' => 'axion_crm_test_a35',
    'DB_CONNECTION' => 'pgsql',
    'APP_ENV' => 'testing',
    'SESSION_DRIVER' => 'database',
    'CACHE_STORE' => 'array',
    'MAIL_MAILER' => 'array',
    'QUEUE_CONNECTION' => 'sync',
    // Chaque 500 « Route [login] not defined » ecrit 8 475 octets dans
    // storage/logs/laravel.log — fichier PARTAGE avec les autres agents, et deja
    // a 44 Mo. La sonde en produit une centaine : on n'ecrit pas dedans.
    'LOG_CHANNEL' => 'null',
] as $cle => $valeur) {
    $_SERVER[$cle] = $valeur;
    $_ENV[$cle] = $valeur;
    putenv("{$cle}={$valeur}");
}

require '/var/www/html/vendor/autoload.php';
