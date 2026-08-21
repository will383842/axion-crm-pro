<?php

/**
 * GARDE DE TELESCOPE — constats A-007 / F40-003 (S1).
 *
 * ── LE CONSTAT, ET CE QUE LA MESURE A CORRIGE DANS SON EXPLICATION ──────────
 *
 * Constat d'origine : « Telescope tourne en production sans ses tables : le
 * journal pese 270 Mo, grossit d'environ 90 Mo par jour, et 100 % de ce qu'il
 * ecrit est le meme defaut. »
 *
 * Le defaut lui-meme est connu et documente (`_REPORTS/RUNBOOK-CONSOLE-LOCALE.md`
 * §3) : `laravel/telescope` est une dependance DURE (`composer.json:21`, section
 * `require`, donc presente meme avec `composer install --no-dev`), et AUCUNE
 * migration Telescope n'existe dans `backend/database/migrations/`. Telescope
 * enregistre a la TERMINAISON de chaque requete et de chaque commande artisan :
 *
 *     SQLSTATE[42P01] relation "telescope_entries" does not exist
 *
 * ── CE QUE J'AI MESURE, ET QUI N'ETAIT PAS DIT ──────────────────────────────
 *
 * Le depot croyait le probleme deja traite : `App\Providers\TelescopeServiceProvider`
 * sort de `register()` ET de `boot()` si `env('TELESCOPE_ENABLED', false)` est
 * faux, et son docblock affirme « Telescope est desactive par defaut en prod ».
 *
 * C'EST FAUX, ET LA MESURE LE DIT. `composer.json` porte
 * `extra.laravel.dont-discover: []` : le fournisseur du PAQUET,
 * `Laravel\Telescope\TelescopeServiceProvider`, est donc DECOUVERT AUTOMATIQUEMENT
 * et s'enregistre en plus de celui de l'application. Lui ne lit pas `env()`, il
 * lit `config('telescope.enabled')` — et `backend/config/` ne contenait AUCUN
 * `telescope.php` (verifie : 20 fichiers, aucun). La configuration servie etait
 * donc celle du paquet :
 *
 *     vendor/laravel/telescope/config/telescope.php:19
 *     'enabled' => env('TELESCOPE_ENABLED', true),      ← VRAI par defaut
 *
 * Mesure du defaut, dans un processus neuf, le 2026-08-20 :
 *
 *     $ env -u TELESCOPE_ENABLED APP_ENV=production php sonde-telescope.php
 *     telescope.enabled=true
 *     fournisseurs Telescope charges: Laravel\Telescope\TelescopeServiceProvider,
 *                                     App\Providers\TelescopeServiceProvider
 *     Telescope::isRecording=true
 *
 * Le court-circuit du fournisseur applicatif ne protegeait donc RIEN : il
 * economisait quelques `require`, pendant que le fournisseur du paquet appelait
 * `Telescope::start()` juste a cote. Un correctif qui a l'air d'en etre un est
 * pire qu'un defaut nu : il ferme le dossier.
 *
 * ── LE CORRECTIF ────────────────────────────────────────────────────────────
 *
 * `backend/config/telescope.php` publie la configuration du paquet en
 * n'en changeant qu'une chose : `enabled` a pour defaut FALSE. Les deux
 * lectures (fournisseur applicatif et fournisseur du paquet) s'accordent enfin
 * sur la meme valeur.
 *
 * ── CE QUE CETTE GARDE MESURE ───────────────────────────────────────────────
 *
 * Elle DEMARRE l'application dans un processus neuf et regarde ce que Telescope
 * fait vraiment (`Telescope::isRecording()`), au lieu de relire un fichier qui
 * peut mentir — c'est exactement la lecture de fichier qui avait laisse passer
 * ce constat. Elle porte trois temoins :
 *
 *   · la sonde demarre et sait rendre `true` (sinon tout serait un faux vert) ;
 *   · le chemin d'activation delibere marche TOUJOURS (TELESCOPE_ENABLED=true) —
 *     sans quoi le correctif casserait l'outil au lieu de le ranger ;
 *   · le chemin de la suite de tests (TELESCOPE_ENABLED=false) reste intact.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * Ecrit la sonde qui DEMARRE l'application et interroge Telescope.
 *
 * On demarre vraiment le noyau : `config('telescope.enabled')` seul ne dirait
 * pas si le fournisseur du paquet a appele `Telescope::start()`. C'est
 * `isRecording()` qui repond a la question posee par le constat — « Telescope
 * tourne-t-il ? » — et non un tableau de configuration.
 */
function cheminSondeTelescope(): string
{
    $chemin = sys_get_temp_dir() . '/a35-sonde-telescope.php';

    $source = <<<'PHP'
    <?php
    // Sonde des constats A-007 / F40-003.
    require __CHEMIN_AUTOLOAD__;
    $app = require __CHEMIN_BOOTSTRAP__;
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

    echo 'app_env=' . var_export($app->environment(), true) . "\n";

    // Ce que la sonde a REELLEMENT vu comme variable : si un `.env` du depot
    // definissait TELESCOPE_ENABLED, le scenario « valeur absente » ne
    // mesurerait pas le DEFAUT mais ce `.env`. Le temoin le detecte.
    echo 'telescope_enabled_vu=' . var_export(env('TELESCOPE_ENABLED', null), true) . "\n";

    echo 'enabled=' . var_export((bool) config('telescope.enabled'), true) . "\n";

    $charges = array_filter(
        array_keys($app->getLoadedProviders()),
        fn (string $n): bool => str_contains($n, 'Telescope'),
    );
    echo 'fournisseurs=' . implode(',', $charges) . "\n";

    echo 'enregistre=' . var_export(Laravel\Telescope\Telescope::isRecording(), true) . "\n";

    // Le portillon `viewTelescope` n'est defini que par le `boot()` du
    // fournisseur APPLICATIF. C'est donc le seul temoin qui dise si les DEUX
    // fournisseurs ont pris la meme decision : le fournisseur du paquet peut
    // demarrer Telescope pendant que l'applicatif se court-circuite.
    echo 'portillon=' . var_export(
        Illuminate\Support\Facades\Gate::has('viewTelescope'),
        true,
    ) . "\n";

    // Telescope a-t-il ete DEMANDE puis REFUSE ? C'est la difference entre
    // « personne n'a rien demande » et « quelqu'un a pose la variable et le
    // depot a dit non ». Les deux rendent `enregistre=false` : sans ce
    // temoin, la garde du refus serait indiscernable de la garde du defaut.
    echo 'refus=' . var_export((bool) config('telescope.enabled_refuse'), true) . "\n";
    PHP;

    $source = str_replace(
        ['__CHEMIN_AUTOLOAD__', '__CHEMIN_BOOTSTRAP__'],
        [
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('bootstrap/app.php'), true),
        ],
        $source,
    );

    file_put_contents($chemin, $source);

    return $chemin;
}

/**
 * Joue la sonde avec l'environnement demande, dans un PROCESSUS NEUF.
 *
 * `env -u TELESCOPE_ENABLED` retire d'abord la valeur posee par
 * `phpunit-lot1.xml` : sans ce retrait, le scenario « variable absente » — le
 * scenario de la PRODUCTION — serait impossible a jouer depuis le banc.
 *
 * @param  array<string, string>  $environnement
 * @return array{code: int, sortie: string, enabled: ?bool, enregistre: ?bool, portillon: ?bool, refus: ?bool, vu: string}
 */
function sondeTelescope(array $environnement): array
{
    $commande = 'env -u TELESCOPE_ENABLED -u APP_ENV -u APP_DEBUG';
    // Cle de forme valide : le demarrage du noyau la reclame. Elle ne chiffre
    // rien ici, la sonde n'ouvre aucune session.
    $environnement += [
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        // Coupe le bruit du refus de simulacre (C18-016) en environnement de
        // production, qui n'a rien a voir avec ce qu'on mesure ici.
        'MOCK_MODE' => 'false',

        // ⚠️ MESURE PAYEE, ET ELLE NE VENAIT PAS DU DEPOT MAIS DU BANC.
        //
        // Le bootstrap des bancs d'essai de l'audit pose `LOG_CHANNEL=null`
        // par `putenv()` — donc HERITE par ce processus enfant. Le refus de
        // Telescope partait alors dans le NullHandler, et la garde du signal
        // (« le refus n est PAS SILENCIEUX ») rougissait sur un correctif
        // parfaitement correct : elle aurait mesure la plomberie du banc, pas
        // le comportement du depot.
        //
        // On epingle donc le canal. `stderr` existe dans `config/logging.php`
        // (ligne 83) et la commande capture `2>&1` : ce que le depot journalise
        // arrive dans `sortie`, quel que soit le banc qui joue la garde.
        'LOG_CHANNEL' => 'stderr',
        'LOG_LEVEL' => 'debug',
    ];
    foreach ($environnement as $cle => $valeur) {
        $commande .= ' ' . escapeshellarg($cle . '=' . $valeur);
    }
    $commande .= ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(cheminSondeTelescope()) . ' 2>&1';

    $lignes = [];
    $code = 0;
    exec($commande, $lignes, $code);
    $sortie = implode("\n", $lignes);

    $lire = static function (string $cle) use ($sortie): ?bool {
        if (preg_match('/^' . $cle . '=(true|false)$/m', $sortie, $m) === 1) {
            return $m[1] === 'true';
        }

        return null;
    };

    $vu = '';
    if (preg_match('/^telescope_enabled_vu=(.*)$/m', $sortie, $m) === 1) {
        $vu = trim($m[1]);
    }

    return [
        'code' => $code,
        'sortie' => $sortie,
        'enabled' => $lire('enabled'),
        'enregistre' => $lire('enregistre'),
        'portillon' => $lire('portillon'),
        'refus' => $lire('refus'),
        'vu' => $vu,
    ];
}

test('A-007 — TEMOIN : le paquet Telescope est bien la, et la sonde demarre', function () {
    // Si `laravel/telescope` disparaissait de `composer.json`, tout ce fichier
    // deviendrait un vert sans objet. On le dit plutot que de l'ignorer : un
    // test ignore est un vert deguise.
    $configPaquet = base_path('vendor/laravel/telescope/config/telescope.php');
    expect(is_file($configPaquet))->toBeTrue(
        "Le paquet `laravel/telescope` n'est plus installe ({$configPaquet} absent). "
        . 'Cette garde ne mesurerait plus rien : soit le paquet a ete retire de composer.json '
        . '— et il faut alors SUPPRIMER ce fichier de test et `config/telescope.php` — soit le '
        . '`vendor` du banc est incomplet.',
    );

    $resultat = sondeTelescope(['APP_ENV' => 'local', 'TELESCOPE_ENABLED' => 'true']);

    expect($resultat['code'])->toBe(
        0,
        "La sonde n'a pas pu demarrer l'application. Tout ce qui suit serait un vert sans "
        . "mesure.\nSortie :\n" . $resultat['sortie'],
    );

    // TEMOIN POSITIF, LE PLUS IMPORTANT DU FICHIER. Sans lui, une sonde cassee
    // qui repondrait toujours `false` rendrait vertes les gardes ci-dessous
    // sans que Telescope soit desactive pour autant.
    expect($resultat['enregistre'])->toBeTrue(
        'La sonde ne voit PLUS Telescope enregistrer, meme quand on le lui demande '
        . "explicitement (TELESCOPE_ENABLED=true en local). Elle ne mesure donc plus rien.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );

    // Et elle voit bien les DEUX fournisseurs : c'est le coeur du constat.
    $this->assertStringContainsString(
        'Laravel\Telescope\TelescopeServiceProvider',
        $resultat['sortie'],
        "Le fournisseur du PAQUET n'apparait plus. C'est lui qui appelait `Telescope::start()` "
        . 'malgre le court-circuit du fournisseur applicatif : sans lui, ce fichier ne garde '
        . "plus le defaut qu'il decrit.",
    );
});

test('A-007 — sans TELESCOPE_ENABLED, Telescope N ENREGISTRE PAS en production', function () {
    $resultat = sondeTelescope(['APP_ENV' => 'production']);

    // TEMOIN DE SCENARIO : on doit avoir mesure le DEFAUT, pas un `.env` du
    // depot. Si un fichier `.env` definissait la variable, `vu` ne serait pas
    // NULL et le scenario « valeur absente » serait un mensonge.
    expect($resultat['vu'])->toBe(
        'NULL',
        "La sonde n'a pas mesure le DEFAUT : `TELESCOPE_ENABLED` lui est parvenue avec la "
        . "valeur {$resultat['vu']}, probablement depuis un fichier `.env` du depot. Le "
        . "scenario joue n'est donc pas celui de la production, ou la variable est absente.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );

    expect($resultat['enabled'])->toBeFalse(
        "`config('telescope.enabled')` vaut VRAI par defaut en production.\n\n"
        . '`laravel/telescope` est une dependance DURE (composer.json:21, section require) et '
        . "AUCUNE migration Telescope n'existe dans backend/database/migrations/. Telescope "
        . 'enregistre a la terminaison de CHAQUE requete et de CHAQUE commande artisan, et '
        . 'chaque enregistrement echoue sur `relation "telescope_entries" does not exist` — '
        . "270 Mo de journal, environ 90 Mo par jour, 100 % du meme defaut.\n\n"
        . '⚠️ Le court-circuit de `App\\Providers\\TelescopeServiceProvider` NE SUFFIT PAS : '
        . '`composer.json` porte `dont-discover: []`, donc le fournisseur du PAQUET est '
        . "decouvert et lit `config('telescope.enabled')`, pas `env()`.\n\n"
        . "Correctif : publier `backend/config/telescope.php` avec `enabled` a defaut FALSE.\n\n"
        . "Sortie de la sonde :\n" . $resultat['sortie'],
    );

    // La question du constat n'est pas « que dit la configuration » mais
    // « Telescope tourne-t-il ». C'est celle-la qu'on tranche.
    expect($resultat['enregistre'])->toBeFalse(
        "Telescope ENREGISTRE en production alors qu'aucune de ses tables n'existe. "
        . "C'est le constat A-007 / F40-003 en entier.\n\n"
        . "Sortie de la sonde :\n" . $resultat['sortie'],
    );
});

test('A-007 — sans TELESCOPE_ENABLED, Telescope N ENREGISTRE PAS en preproduction', function () {
    // La preproduction porte les memes tables que la production — c'est-a-dire
    // aucune table Telescope. Le meme journal s'y remplirait du meme defaut,
    // sur un disque qu'on surveille encore moins.
    $resultat = sondeTelescope(['APP_ENV' => 'staging']);

    expect($resultat['enregistre'])->toBeFalse(
        "Telescope ENREGISTRE en preproduction alors qu'aucune de ses tables n'existe.\n"
        . "Sortie de la sonde :\n" . $resultat['sortie'],
    );
});

test('A-007 — le chemin deja emprunte par la suite de tests reste intact', function () {
    // ⚠️ `phpunit-lot1.xml` pose deja `TELESCOPE_ENABLED=false`. Le correctif ne
    // doit pas casser cette lecture — sinon la suite entiere changerait de
    // comportement pour une raison sans rapport avec ce qu'elle teste.
    $resultat = sondeTelescope(['APP_ENV' => 'testing', 'TELESCOPE_ENABLED' => 'false']);

    expect($resultat['enabled'])->toBeFalse();
    expect($resultat['enregistre'])->toBeFalse();

    // Et la forme piegeuse : `(bool) "false"` vaut TRUE en PHP. La lecture doit
    // rendre faux sur la CHAINE « false », comme `env()` le fait.
    expect($resultat['vu'])->toBe('false');
});

test('A-007 — Telescope reste ACTIVABLE deliberement, sans redeploiement', function () {
    // Contre-garde. Un correctif ecrit trop large (un `false` en dur) volerait
    // l'outil a ceux qui s'en servent, et quelqu'un finirait par retirer la
    // garde en entier. Le drapeau doit continuer de commander.
    //
    // ⚠️ Rappel pour qui l'activerait vraiment : sans
    // `php artisan telescope:install` puis `migrate`, l'activation reproduit
    // exactement le defaut de production. Le drapeau ne cree pas les tables.
    $resultat = sondeTelescope(['APP_ENV' => 'local', 'TELESCOPE_ENABLED' => 'true']);

    expect($resultat['enabled'])->toBeTrue(
        'Le drapeau TELESCOPE_ENABLED=true ne rallume plus Telescope : le correctif a ete '
        . "ecrit trop large et a supprime l'outil au lieu de le ranger.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );
    expect($resultat['enregistre'])->toBeTrue();
});

/**
 * Joue la sonde SOUS `config:cache`, comme en production.
 *
 * ⚠️ POURQUOI CE SCENARIO EXISTE, ET POURQUOI IL EST LE SEUL A MORDRE.
 *
 * Quand la configuration est mise en cache, Laravel NE CHARGE PLUS le fichier
 * `.env` du tout (`LoadEnvironmentVariables` est court-circuite). Une valeur qui
 * ne vit QUE dans ce fichier — et non dans l'environnement du processus —
 * devient donc invisible a `env()`, alors qu'elle reste figee dans le cache et
 * reste visible a `config()`.
 *
 * C'est exactement la fissure entre les deux fournisseurs de Telescope : le
 * paquet lit `config()`, l'applicatif lisait `env()`.
 *
 * ⚠️ `APP_CONFIG_CACHE` deplace le cache vers `/tmp`. C'est indispensable :
 * `bootstrap/cache/config.php` est PARTAGE avec les autres agents qui
 * travaillent dans ce depot, et un cache oublie la-bas figerait leur
 * configuration sans qu'ils comprennent pourquoi.
 *
 * @param  array<string, string>  $environnementDuCache  ce que voit `config:cache`
 * @param  array<string, string>  $environnementDExecution  ce que voit le processus ensuite
 * @return array{code: int, sortie: string, enabled: ?bool, enregistre: ?bool, portillon: ?bool, vu: string}
 */
function sondeTelescopeSousConfigCache(array $environnementDuCache, array $environnementDExecution): array
{
    $cache = sys_get_temp_dir() . '/a35-cache-config-' . getmypid() . '.php';
    @unlink($cache);

    $base = [
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'MOCK_MODE' => 'false',
        'APP_CONFIG_CACHE' => $cache,
    ];

    $commande = 'cd ' . escapeshellarg(base_path()) . ' && env -u TELESCOPE_ENABLED -u APP_ENV -u APP_DEBUG';
    foreach ($environnementDuCache + $base as $cle => $valeur) {
        $commande .= ' ' . escapeshellarg($cle . '=' . $valeur);
    }
    // ⚠️ `|| true` : avec Telescope allume et sans ses tables, le crochet de
    // terminaison de `artisan` echoue — c'est LE defaut A-007 lui-meme. Le
    // fichier de cache est ecrit avant, et c'est lui qui nous interesse.
    $commande .= ' ' . escapeshellarg(PHP_BINARY) . ' artisan config:cache 2>&1 || true';
    exec($commande);

    try {
        if (! is_file($cache)) {
            return [
                'code' => 1,
                'sortie' => "config:cache n'a produit aucun fichier ({$cache}).",
                'enabled' => null, 'enregistre' => null, 'portillon' => null,
                'refus' => null, 'vu' => '',
            ];
        }

        return sondeTelescope($environnementDExecution + ['APP_CONFIG_CACHE' => $cache]);
    } finally {
        @unlink($cache);
    }
}

test('A-007 — sous config:cache, les DEUX fournisseurs prennent la MEME decision', function () {
    // Le scenario exact de la fissure : la valeur a ete posee dans le `.env`
    // au moment du `config:cache`, puis le processus tourne SANS cette variable
    // dans son environnement — ce qui est le cas des le moment ou la
    // configuration est mise en cache, `.env` n'etant alors plus lu.
    $resultat = sondeTelescopeSousConfigCache(
        ['APP_ENV' => 'local', 'TELESCOPE_ENABLED' => 'true'],
        ['APP_ENV' => 'local'],
    );

    // TEMOIN DE SCENARIO : la variable doit bien etre INVISIBLE au processus,
    // sinon on ne joue pas la fissure et le test ne prouve rien.
    expect($resultat['vu'])->toBe(
        'NULL',
        "La variable est encore visible via `env()` : le scenario joue n'est pas celui de "
        . "`config:cache`, et ce test ne prouve rien.\nSortie :\n" . $resultat['sortie'],
    );

    // Le fournisseur du PAQUET lit le cache : Telescope demarre.
    expect($resultat['enabled'])->toBeTrue(
        "Le cache de configuration n'a pas fige TELESCOPE_ENABLED=true : la fissure ne peut "
        . "pas etre jouee.\nSortie :\n" . $resultat['sortie'],
    );
    expect($resultat['enregistre'])->toBeTrue();

    // Et le fournisseur APPLICATIF doit avoir pris la meme decision. Avec
    // `env()`, il rendait NULL ici, se court-circuitait, et laissait les routes
    // `/telescope` du paquet enregistrees SANS `Telescope::auth()` ni portillon
    // `viewTelescope`.
    expect($resultat['portillon'])->toBeTrue(
        'FISSURE : le fournisseur du PAQUET a demarre Telescope, le fournisseur APPLICATIF '
        . "s'est court-circuite. Sous `config:cache`, Laravel ne lit plus `.env` : `env()` "
        . 'rend NULL la ou `config()` rend la valeur figee. Les routes `/telescope` existent '
        . "alors sans `Telescope::auth()` ni portillon `viewTelescope`.\n\n"
        . 'Correctif : `App\\Providers\\TelescopeServiceProvider` doit lire '
        . "`config('telescope.enabled')`, la MEME source que le fournisseur du paquet.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );
});

test('A-007 — les DEUX fournisseurs prennent la meme decision', function () {
    // LA DIVERGENCE QUI RESTAIT APRES LE PREMIER CORRECTIF.
    //
    // `App\Providers\TelescopeServiceProvider` lisait `env('TELESCOPE_ENABLED')`
    // pendant que le fournisseur du PAQUET lit `config('telescope.enabled')`.
    // Sous `config:cache` — actif en production — `env()` hors configuration
    // rend NULL : les deux pouvaient se contredire, le paquet demarrant
    // Telescope pendant que l'applicatif se court-circuitait. Dans cet etat,
    // les routes `/telescope` existaient mais ni `Telescope::auth()` ni le
    // portillon `viewTelescope` n'etaient definis.
    //
    // Le portillon est le SEUL temoin qui distingue les deux : il n'est pose
    // que par le `boot()` du fournisseur applicatif.
    $allume = sondeTelescope(['APP_ENV' => 'local', 'TELESCOPE_ENABLED' => 'true']);
    expect($allume['portillon'])->toBeTrue(
        "Telescope enregistre mais le portillon `viewTelescope` n'est pas defini : le "
        . 'fournisseur du PAQUET a demarre pendant que le fournisseur APPLICATIF se '
        . "court-circuitait. Les deux ne lisent pas la meme source.\n"
        . "Sortie :\n" . $allume['sortie'],
    );

    $eteint = sondeTelescope(['APP_ENV' => 'production']);
    expect($eteint['portillon'])->toBeFalse(
        "Le fournisseur applicatif a demarre alors que Telescope est eteint.\n"
        . "Sortie :\n" . $eteint['sortie'],
    );
});

/*
|==============================================================================
| DEUXIEME MOITIE DU CONSTAT : UN DEFAUT N'EST PAS UN REFUS
|==============================================================================
|
| Tout ce qui precede prouve que Telescope est ETEINT PAR DEFAUT. Ca ne prouve
| pas qu'il est ETEINT. Mesure du 2026-08-21, sur le depot deja corrige :
|
|     $ env -u TELESCOPE_ENABLED -u APP_ENV \
|         APP_ENV=production TELESCOPE_ENABLED=true php sonde-telescope.php
|     app_env='production'
|     enabled=true
|     enregistre=true
|
| La production ne tenait donc qu'a l'ABSENCE d'une variable — dans un depot ou
| `MOCK_MODE` (C18-016) et `APP_DEBUG` (F37-003) ont chacun deja ete poses de
| travers sur un deploiement reel, et ou `docker compose restart` ne relit pas
| `env_file` (A07-003), si bien qu'une valeur fausse survit a sa correction.
|
| Et l'enjeu n'est pas que le disque. Telescope journalise les requetes HTTP,
| les requetes SQL avec leurs parametres, les jobs et les courriels : ce qu'il
| ecrirait en production, ce sont des donnees personnelles et des secrets, dans
| une table que rien ici ne purge.
*/

/**
 * Enumere les environnements de DEPLOIEMENT du depot — par les fichiers qui les
 * declarent, jamais par une liste ecrite a la main.
 *
 * ⚠️ C'est le motif A-011 du depot : une garde de completude qui enumere une
 * liste ecrite a la main enumere exactement les cas deja repares. On lit donc
 * le CATALOGUE — les descripteurs de deploiement (`docker-compose*.yml`) et les
 * ateliers CI (`.github/workflows/*.yml`) — et on retire les deux
 * environnements ou Telescope reste legitimement activable.
 *
 * Si un `docker-compose.preprod2.yml` apparait un jour avec `APP_ENV: qa`, il
 * entre AUTOMATIQUEMENT dans le balayage ci-dessous.
 *
 * @return list<string>
 */
function a007EnvironnementsDeployes(): array
{
    $racine = realpath(base_path('..')) ?: base_path('..');

    $fichiers = array_merge(
        glob($racine . '/docker-compose*.yml') ?: [],
        glob($racine . '/.github/workflows/*.yml') ?: [],
    );

    $vus = [];
    foreach ($fichiers as $fichier) {
        $contenu = (string) file_get_contents($fichier);

        // `APP_ENV: production` (compose) comme `APP_ENV=production` (shell).
        // Le motif ne mord pas sur `APP_ENV: ${APP_ENV}` : une valeur qu'on ne
        // peut pas resoudre statiquement n'est pas une valeur mesurable.
        $motif = '/\bAPP_ENV\b\s*[:=]\s*["\']?([A-Za-z_][A-Za-z0-9_-]*)/';

        if (preg_match_all($motif, $contenu, $trouves) === 0) {
            continue;
        }

        foreach ($trouves[1] as $valeur) {
            $vus[strtolower($valeur)] = true;
        }
    }

    // Les deux seuls ou ce que Telescope enregistre ne peut atteindre personne
    // d'autre que celui qui l'a provoque (meme liste que `config/app.php`).
    unset($vus['local'], $vus['testing']);

    $liste = array_keys($vus);
    sort($liste);

    return $liste;
}

test('A-007 — TEMOIN DE COUVERTURE : le balayage des deploiements voit vraiment quelque chose', function () {
    // Sans ce cas, un balayage casse — mauvaise racine, glob qui ne trouve
    // rien, motif qui ne mord plus — rendrait la garde de completude VERTE en
    // n'enumerant AUCUN environnement. C'est exactement le faux vert que ce
    // depot a deja paye : une garde qui enumerait une liste de deux fichiers
    // contenant exactement les deux fichiers deja repares.
    $environnements = a007EnvironnementsDeployes();

    $this->assertContains(
        'production',
        $environnements,
        'Le balayage des descripteurs de deploiement ne trouve plus `production`. Il '
        . "n'enumere donc plus rien de ce qu'il doit garder : la garde de completude "
        . "ci-dessous serait VERTE sans mesurer un seul environnement.\n"
        . 'Racine balayee : ' . (realpath(base_path('..')) ?: base_path('..')) . "\n"
        . 'Trouve : ' . (implode(', ', $environnements) ?: '(rien)'),
    );

    $this->assertContains(
        'staging',
        $environnements,
        'Le balayage ne trouve plus `staging`, alors que `docker-compose.staging.yml` le '
        . 'declare. La preproduction est servie sur des noms PUBLICS par le Caddy de '
        . "production (cf. constat F37-003) : c'est le deuxieme environnement a garder, "
        . "pas un detail.\n"
        . 'Trouve : ' . (implode(', ', $environnements) ?: '(rien)'),
    );
});

test('A-007 — en PRODUCTION, une activation EXPLICITE est REFUSEE', function () {
    // LE CAS QUE LE PREMIER CORRECTIF LAISSAIT OUVERT.
    $resultat = sondeTelescope(['APP_ENV' => 'production', 'TELESCOPE_ENABLED' => 'true']);

    // TEMOIN DE SCENARIO : on doit bien avoir DEMANDE Telescope. Si la variable
    // n'atteignait pas le processus, ce test mesurerait le defaut (deja garde
    // plus haut) et non le refus, tout en restant vert.
    expect($resultat['vu'])->toBe(
        'true',
        "La sonde n'a pas recu TELESCOPE_ENABLED=true : le scenario joue n'est pas celui du "
        . "refus, et ce test ne prouve rien.\nSortie :\n" . $resultat['sortie'],
    );

    expect($resultat['enabled'])->toBeFalse(
        "`config('telescope.enabled')` est VRAI en production parce qu'une variable le "
        . "demande. Un DEFAUT n'est pas un REFUS : la production ne doit pas tenir a "
        . "l'ABSENCE d'une variable, dans un depot ou MOCK_MODE (C18-016) et APP_DEBUG "
        . '(F37-003) ont chacun deja ete poses de travers sur un deploiement reel, et ou '
        . "`docker compose restart` ne relit pas env_file (A07-003).\n\n"
        . 'Correctif : `config/telescope.php` doit neutraliser la demande hors '
        . '`local`/`testing`, comme `config/app.php` le fait pour APP_DEBUG et comme '
        . "`MockServicesProvider::drapeau()` le fait pour les simulacres.\n\n"
        . "Sortie :\n" . $resultat['sortie'],
    );

    expect($resultat['enregistre'])->toBeFalse(
        "Telescope ENREGISTRE en production sur simple pose d'une variable, alors qu'aucune "
        . "de ses tables n'existe — et il journalise les requetes HTTP, les requetes SQL avec "
        . 'leurs parametres, les jobs et les courriels : donc des donnees personnelles et des '
        . "secrets.\nSortie :\n" . $resultat['sortie'],
    );

    expect($resultat['portillon'])->toBeFalse(
        'Le fournisseur APPLICATIF a demarre alors que Telescope est refuse : les deux '
        . "fournisseurs ne prennent plus la meme decision.\nSortie :\n" . $resultat['sortie'],
    );
});

test('A-007 — dans CHAQUE environnement deploye, une activation explicite est REFUSEE', function () {
    // GARDE DE COMPLETUDE (motif A-011). Enumeree par le CATALOGUE des
    // descripteurs de deploiement, pas par une liste ecrite ici : un nouvel
    // environnement ajoute a `docker-compose*.yml` entre dans ce balayage sans
    // que personne n'ait a y penser.
    $environnements = a007EnvironnementsDeployes();
    expect($environnements)->not->toBeEmpty();

    foreach ($environnements as $environnement) {
        $resultat = sondeTelescope([
            'APP_ENV' => $environnement,
            'TELESCOPE_ENABLED' => 'true',
        ]);

        expect($resultat['enregistre'])->toBeFalse(
            "Telescope ENREGISTRE en environnement « {$environnement} » alors qu'une variable "
            . "l'a explicitement demande. Cet environnement est declare par un descripteur de "
            . 'deploiement du depot : il porte les memes tables que la production, '
            . "c'est-a-dire AUCUNE table Telescope.\nSortie :\n" . $resultat['sortie'],
        );
    }
});

test('A-007 — le refus n est PAS SILENCIEUX', function () {
    // Un refus muet est la moitie d'un defaut : l'operateur qui pose la
    // variable pour regarder une requete ne verrait RIEN, chercherait la panne
    // ailleurs, ou « reparerait » la garde. Meme lecon que `app.debug_refuse`.
    $resultat = sondeTelescope(['APP_ENV' => 'production', 'TELESCOPE_ENABLED' => 'true']);

    expect($resultat['refus'])->toBeTrue(
        "`config('telescope.enabled_refuse')` est faux alors que Telescope vient d'etre "
        . "refuse. Sans ce drapeau, aucun signal ne peut etre emis.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );

    // ⚠️ Sous-chaine SANS ACCENT : le message journalise est en francais non
    // accentue, comme les autres refus du depot.
    $this->assertStringContainsString(
        'Telescope REFUSE hors poste de developpement',
        $resultat['sortie'],
        'Telescope a ete refuse EN SILENCE. `App\\Providers\\TelescopeServiceProvider::boot()` '
        . "doit journaliser `config('telescope.enabled_refuse')` au niveau critique, avant son "
        . "court-circuit — c'est le geste deja pose par `AppServiceProvider` pour "
        . "`app.debug_refuse` (F37-003).\nSortie :\n" . $resultat['sortie'],
    );

    // ⚠️ Le niveau ET le message, sur la MEME ligne. Chercher `CRITICAL` seul
    // serait un faux vert : la sonde demarre en environnement de production, ou
    // d'AUTRES refus du depot journalisent deja au niveau critique — le refus
    // de simulacre (C18-016) et le refus de debogage (F37-003). L'aiguille doit
    // donc porter les deux.
    $this->assertStringContainsString(
        'CRITICAL: Telescope REFUSE',
        $resultat['sortie'],
        'Le refus est journalise, mais pas au niveau critique. Une configuration de '
        . "production fautive n'est pas une information.\nSortie :\n" . $resultat['sortie'],
    );
});

test('A-007 — TEMOIN NEGATIF : aucun cri de refus quand Telescope est legitimement allume', function () {
    // Prouve que l'instrument DISCRIMINE. Un fournisseur qui crierait au refus
    // a chaque demarrage rendrait le test precedent vert sans rien garder.
    $resultat = sondeTelescope(['APP_ENV' => 'local', 'TELESCOPE_ENABLED' => 'true']);

    expect($resultat['enregistre'])->toBeTrue(
        "Temoin fausse : Telescope n'enregistre plus en local.\nSortie :\n" . $resultat['sortie'],
    );

    expect($resultat['refus'])->toBeFalse(
        '`enabled_refuse` est VRAI alors que Telescope tourne. Le drapeau ne distingue plus '
        . "rien.\nSortie :\n" . $resultat['sortie'],
    );

    $this->assertStringNotContainsString(
        'Telescope REFUSE hors poste de developpement',
        $resultat['sortie'],
        'Le fournisseur crie au refus alors que Telescope est ALLUME. Le signal ne veut plus '
        . "rien dire, et le test du refus serait vert quoi qu'il arrive.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );
});

test('A-007 — quand personne ne demande rien : pas de refus, et pas de cri', function () {
    // La troisieme colonne du tableau. `enregistre=false` a deux causes tres
    // differentes — « rien demande » et « demande, refuse » — et seule la
    // seconde doit crier. Sans ce cas, un `enabled_refuse => true` en dur
    // passerait le test du refus et noierait le journal de production sous un
    // cri emis a chaque demarrage de chaque processus.
    $resultat = sondeTelescope(['APP_ENV' => 'production']);

    expect($resultat['vu'])->toBe('NULL');

    expect($resultat['refus'])->toBeFalse(
        "Le depot signale un refus de Telescope alors que PERSONNE ne l'a demande.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );

    $this->assertStringNotContainsString(
        'Telescope REFUSE hors poste de developpement',
        $resultat['sortie'],
        "Message de refus emis alors qu'aucune variable ne demandait Telescope. Un signal "
        . "emis a chaque demarrage n'est plus un signal.\n"
        . "Sortie :\n" . $resultat['sortie'],
    );
});

test('A-007 — une valeur AMBIGUE n allume pas Telescope', function () {
    // ⚠️ `(bool) "off"` vaut **true** en PHP, comme `(bool) "no"` et
    // `(bool) "0.0"`. `env()` normalise `"false"` mais pas ces formes-la. Un
    // operateur qui ecrit `TELESCOPE_ENABLED=off` en croyant eteindre
    // ALLUMERAIT — c'est la lecon deja payee par `MockServicesProvider`.
    //
    // On mesure en `local`, ou Telescope est AUTORISE : en production, le refus
    // d'environnement rendrait ce cas vert sans rien dire de la lecture.
    foreach (['off', 'no', '0.0'] as $valeur) {
        $resultat = sondeTelescope(['APP_ENV' => 'local', 'TELESCOPE_ENABLED' => $valeur]);

        expect($resultat['enregistre'])->toBeFalse(
            "TELESCOPE_ENABLED=\"{$valeur}\" ALLUME Telescope. La lecture doit passer par "
            . "`filter_var(..., FILTER_VALIDATE_BOOLEAN)` et non par `(bool)`.\n"
            . "Sortie :\n" . $resultat['sortie'],
        );
    }
});
