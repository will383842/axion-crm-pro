<?php

use Illuminate\Support\Str;

/*
|--------------------------------------------------------------------------
| Rôle propriétaire vs rôle applicatif (lot L0 — durcissement de l'isolation)
|--------------------------------------------------------------------------
|
| Constat vérifié EN PROD le 2026-08-14 :
|   SELECT rolname, rolsuper, rolbypassrls FROM pg_roles WHERE rolcanlogin;
|   → axion | t | t
|
| Le rôle applicatif était donc SUPERUSER et BYPASSRLS : la Row Level Security
| posée sur 34 tables était intégralement contournée — ni `FORCE ROW LEVEL
| SECURITY` ni des policies strictes n'y changeraient quoi que ce soit tant que
| l'application se connecte avec ce rôle.
|
| D'où trois connexions :
|   - `pgsql`       : connexion par défaut de l'application. Son utilisateur
|                     bascule sur le rôle NON-PROPRIÉTAIRE quand le drapeau
|                     `crm.db_app_role` est à true (défaut : false → identique
|                     à l'existant, donc INERTE).
|   - `pgsql_owner` : rôle propriétaire, réservé aux MIGRATIONS
|                     (`php artisan migrate --database=pgsql_owner`). Par
|                     défaut, mêmes identifiants que `pgsql` avant bascule.
|   - `pgsql_app`   : rôle applicatif explicite, utilisé par les tests
|                     d'étanchéité pour prouver que la RLS mord vraiment.
|
| ⚠️ `DB_APP_PASSWORD` n'est JAMAIS commité : il est posé dans le `.env` du
| serveur (non versionné) et dans l'environnement de la CI.
*/
$ownerUsername = env('DB_OWNER_USERNAME', env('DB_USERNAME', 'axion'));
$ownerPassword = env('DB_OWNER_PASSWORD', env('DB_PASSWORD', ''));
$appUsername = env('DB_APP_USERNAME', 'axion_app');
$appPassword = env('DB_APP_PASSWORD', '');
$useAppRole = filter_var(env('CRM_DB_APP_ROLE_ENABLED', false), FILTER_VALIDATE_BOOLEAN);

$pgsqlBase = [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'axion_crm'),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => env('DB_SCHEMA', 'public'),
    'sslmode' => env('DB_SSLMODE', 'prefer'),
];

return [
    'default' => env('DB_CONNECTION', 'pgsql'),

    'connections' => [
        'pgsql' => $pgsqlBase + [
            'username' => $useAppRole ? $appUsername : env('DB_USERNAME', 'axion'),
            'password' => $useAppRole ? $appPassword : env('DB_PASSWORD', ''),
        ],

        // Rôle propriétaire — migrations et opérations DDL uniquement.
        'pgsql_owner' => $pgsqlBase + [
            'username' => $ownerUsername,
            'password' => $ownerPassword,
        ],

        // Rôle applicatif non-propriétaire — soumis à la RLS.
        'pgsql_app' => $pgsqlBase + [
            'username' => $appUsername,
            'password' => $appPassword,
        ],
    ],

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    'redis' => [
        'client' => env('REDIS_CLIENT', 'predis'),
        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug(env('APP_NAME', 'axion-crm'), '_') . '_database_'),
        ],
        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
        ],
        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
        ],
        'queue' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_QUEUE_DB', '2'),
        ],
    ],
];
