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

/*
 * D29-010 — « POSÉE À VIDE » N'EST PAS « ABSENTE », ET C'EST POURTANT LE GESTE
 * NATUREL POUR DÉSACTIVER LE RÉGLAGE.
 *
 * Le commentaire de la clé `timezone` (plus bas) ne raisonnait que sur le cas
 * « clé jamais renseignée » : `env()` rend `null`, `configureTimezone()` teste
 * `isset()`, rien n'est émis. Mais quelqu'un qui veut revenir en arrière écrit
 * `DB_TIMEZONE=` dans le `.env` — et `env()` rend alors la chaîne VIDE, pour
 * laquelle `isset()` est VRAI. Le connecteur émet `SET TIME ZONE ''` à CHAQUE
 * ouverture de connexion, que Postgres refuse : ce n'est pas un horodatage qui
 * dérape, c'est toute l'API qui tombe, y compris `/up`.
 *
 * On ramène donc le vide à l'absence. Le seul moyen de désactiver le réglage
 * redevient sûr, quelle que soit la main qui l'écrit.
 */
$dbTimezone = env('DB_TIMEZONE');
$dbTimezone = (is_string($dbTimezone) && trim($dbTimezone) === '') ? null : $dbTimezone;

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

    // 🔴 LE DÉCALAGE DE 2 HEURES — corrigé le 2026-08-16.
    //
    // Trois réglages se contredisaient :
    //   · `app.timezone` = Europe/Paris → Carbon vit à Paris ;
    //   · aucune `timezone` ici → la session Postgres restait sur le défaut
    //     serveur, `Etc/UTC` ;
    //   · `PostgresGrammar::getDateFormat()` = `Y-m-d H:i:s`, donc SANS
    //     décalage.
    //
    // Une écriture applicative partait en `"2026-08-16 07:22:56"` nu, que
    // Postgres lisait comme 07:22:56 **UTC**, soit 09:22:56 à Paris :
    // l'instant stocké était postérieur de 2 h à l'instant réel (1 h en heure
    // d'hiver — l'erreur vaut toujours le décalage de Paris à cette date).
    // Relu, l'horodatage revenait +120 min. Mesuré, pas déduit.
    //
    // Conséquence la plus coûteuse : `ScrapingCampaign::elapsed_minutes`
    // compare un `started_at` relu (2 h dans le futur) à `now()` (réel), donc
    // `durée_réelle − 120`. Une campagne plafonnée à 3 h en tournait 5.
    // Le défaut était déjà décrit dans `tests/Feature/CampaignsTest.php`, avec
    // la consigne de ne pas ajouter de `->refresh()` — les tests d'auto-pause
    // ne passaient que parce qu'ils ne traversaient jamais la base.
    //
    // `PostgresConnector::configureTimezone()` émet `SET TIME ZONE` à la
    // connexion : Postgres lit désormais l'heure nue comme une heure de Paris,
    // c'est-à-dire ce que l'application voulait dire. Ce que Postgres écrit
    // lui-même (`DEFAULT now()`) était déjà juste et le reste.
    //
    // ⚠️ INERTE PAR DÉFAUT, ET C'EST VOULU.
    //
    // Non renseignée, la clé vaut `null` : `configureTimezone()` teste
    // `isset()`, donc n'émet rien et le comportement actuel est INCHANGÉ.
    // La bascule se fait en ajoutant `DB_TIMEZONE=Europe/Paris` à
    // `/opt/axion-crm-pro/.env` sur le serveur HETZNER, puis
    // `docker compose up -d --force-recreate --no-deps api horizon scheduler`.
    // ⚠️ `docker compose restart` NE RELIT PAS `env_file`, et le déploiement
    // automatique ne recrée que `api` + `app` : sans ce `--force-recreate`,
    // horizon et scheduler continueraient d'écrire dans l'ancienne convention.
    // (Ce dépôt n'est pas déployé par Coolify — c'est le site Axion-IA qui
    // l'est. Une première rédaction disait « côté Coolify » : c'était faux.)
    //
    // Pourquoi une variable plutôt qu'un défaut en dur : l'ordre des opérations
    // n'est pas négociable. Les lignes déjà stockées sont décalées ; il faut
    // les reprendre (`php artisan horodatages:corriger`) AVANT que
    // l'application se mette à écrire juste. Sinon les nouvelles lignes justes
    // et les anciennes fausses deviennent indistinguables — Laravel écrit
    // toujours à la seconde pleine, dans les deux cas — et le discriminant sur
    // lequel repose la reprise disparaît. Une variable rend cet ordre au
    // pilote ; un défaut en dur le lui volerait au premier déploiement.
    //
    // Les tests, eux, tournent AVEC (cf. `phpunit.xml`) : c'est ce qui rend
    // `HorodatagesFuseauTest` capable de rougir.
    // La valeur passe par `$dbTimezone` (cf. D29-010, en tête de fichier) :
    // une chaîne vide y est ramenée à `null`, donc à « pas de `SET TIME ZONE` ».
    'timezone' => $dbTimezone,
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

        // ═══════════════════════════════════════════════════════════════════
        // C18-011 — LE PONT LARAVEL → NODE, ET POURQUOI IL LUI FAUT SA PROPRE
        // CONNEXION SANS PRÉFIXE.
        // ═══════════════════════════════════════════════════════════════════
        //
        // `options.prefix` ci-dessus (`axion_crm_pro_database_`) est collé
        // devant CHAQUE clef par le client Redis de Laravel. C'est voulu : il
        // isole le cache, les sessions et les files Laravel d'un autre
        // locataire du même serveur Redis.
        //
        // Mais `DispatchScrapeJob` n'écrit pas pour Laravel : il écrit pour un
        // consommateur écrit en TypeScript, `workers/src/scrapers/base.ts`,
        // qui fait `BRPOP` sur la constante NUE de
        // `workers/src/bridge/queues.ts` avec un `ioredis` sans `keyPrefix`.
        // Mesure du 2026-08-21, sur le fil :
        //     émis   : axion_crm_pro_database_axion:scrape:google-maps
        //     écouté : axion:scrape:google-maps
        // Les deux ne se rencontraient jamais. Aucun des deux côtés n'émettait
        // d'erreur : Laravel écrivait sans se plaindre, le worker attendait
        // sans se plaindre. Le pont n'avait, mesure faite, jamais pu marcher.
        //
        // ⚠️ POURQUOI PAS VIDER `options.prefix`, NI CELUI DE `queue`.
        // Le préfixe vide au niveau global déplacerait d'un coup TOUTES les
        // clefs de cache, de session et de file Laravel : ce qui est déjà en
        // vol au moment du déploiement devient orphelin (jobs jamais repris,
        // sessions perdues, verrous `Cache::lock()` dédoublés le temps de leur
        // TTL). La connexion `queue` porte en outre les files de Laravel
        // lui-même (`QUEUE_CONNECTION=redis` → `queue.connections.redis`) :
        // lui retirer son préfixe aurait exactement le même effet. On isole
        // donc le pont, et rien d'autre ne bouge.
        //
        // ⚠️ LA BASE DOIT ÊTRE CELLE DE `WORKER_REDIS_URL`.
        // Côté Node, `workers/src/bridge/redis.ts` lit `WORKER_REDIS_URL`
        // (défaut `redis://localhost:6379/1`). `.env` et `.env.example`
        // posent tous deux `REDIS_QUEUE_DB=1` et
        // `WORKER_REDIS_URL=redis://redis:6379/1` : le défaut retenu ici est
        // donc `1`, celui qui est réellement déployé, et NON le `2` que la
        // connexion `queue` ci-dessus prend par défaut (défaut jamais employé
        // en pratique — cf. la note de `.env.example`). `REDIS_SCRAPE_DB`
        // permet de les découpler si un jour ils divergent.
        'scrape_bridge' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_SCRAPE_DB', env('REDIS_QUEUE_DB', '1')),

            // Clef NUE. `PredisConnector` comme `PhpRedisConnector` font
            // gagner un `prefix` posé au niveau de la connexion sur celui des
            // options globales — vérifié dans les deux connecteurs.
            'prefix' => '',
        ],
    ],
];
