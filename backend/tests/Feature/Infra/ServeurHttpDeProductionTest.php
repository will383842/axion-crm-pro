<?php

/**
 * GARDE D'INFRASTRUCTURE — audit 360, constat A-010 (S0).
 *
 * LE DÉFAUT.
 *
 * L'API de production était servie par le **serveur web intégré de PHP en ligne
 * de commande** (`php -S`), en **un seul processus**. Ce serveur traite une
 * requête à la fois : la Nᵉ requête simultanée attend la fin des N−1
 * précédentes. Mesuré en production le 2026-08-19 sur douze requêtes
 * concurrentes : un escalier parfait, de pas ≈ 15 ms, contre une série
 * séquentielle rigoureusement plate au même instant sur le même point.
 *
 * Conséquences : une requête lente gèle **tous** les utilisateurs ; le principe
 * directeur 8 du cahier des charges (« dix utilisateurs dès le premier jour »)
 * est violé par construction ; et la documentation de PHP dit de ce serveur
 * qu'il « ne devrait pas être utilisé sur un réseau public ».
 *
 * L'ironie du dossier : l'image est bâtie sur `php:8.3-fpm-alpine`. php-fpm
 * était **déjà là**, à `/usr/local/sbin/php-fpm`. Le commentaire du `Dockerfile`
 * disait même « la commande par défaut sera fournie par docker-compose (php-fpm
 * ou artisan) » — sauf que `docker-compose` n'en a jamais fourni aucune, et le
 * `CMD` de repli, `php -S`, servait donc la production.
 *
 * CE QUE LA MESURE A AJOUTÉ À L'ANALYSE DE L'AUDIT.
 *
 * L'audit recommandait « servir par php-fpm ». Ce n'est pas suffisant. Douze
 * requêtes simultanées sur un script trivial de 50 ms, banc isolé, 2026-08-19 :
 *
 *   php -S, un processus ............ 0,054 → 0,562 s   escalier, pas ≈ 47 ms
 *   php-fpm, réglage d'usine ........ 0,080 → 0,316 s   encore un gradient
 *   php-fpm, pool porté à 16 ........ 0,099 → 0,190 s   plat
 *
 * Le réglage d'usine de l'image Docker officielle est `pm.max_children = 5`.
 * Basculer sur php-fpm sans toucher au pool aurait donc plafonné la concurrence
 * à **cinq** — mieux qu'un, toujours en dessous des dix utilisateurs du cahier
 * des charges. Le pool fait partie du correctif, pas de son décor.
 *
 * CE QUE CETTE GARDE PEUT ET NE PEUT PAS PROUVER.
 *
 * Elle lit les fichiers de déploiement. Elle prouve donc que **ce qui sera
 * déployé** ne sert plus par `php -S` et porte un pool suffisant. Elle ne peut
 * pas prouver ce que fait le conteneur qui tourne en ce moment sur le serveur —
 * seule une mesure sur la machine le dira, et le constat A-010 la documente.
 * Les deux sont nécessaires ; celle-ci est celle que l'intégration continue
 * sait rejouer à chaque proposition de modification.
 */

use Tests\TestCase;

uses(TestCase::class);

/** Racine du dépôt vue depuis l'application Laravel. */
function racineDepotHttp(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

function lireFichierDepot(string $relatif): string
{
    $chemin = racineDepotHttp() . '/' . ltrim($relatif, '/');
    expect(file_exists($chemin))->toBeTrue(
        "Le banc ne voit pas {$relatif}. Une garde qui n'a rien à inspecter passe au vert " .
        'sans rien prouver : monte la racine du dépôt avant de la croire.'
    );

    return file_get_contents($chemin) ?: '';
}

/**
 * TÉMOIN DE MONTAGE — le piège numéro un de ce dossier.
 *
 * `PurgesDestructricesTest` et consorts ont déjà payé ce prix : une garde qui
 * inspecte des fichiers absents ne trouve aucune faute et passe au vert. C'est
 * le pire des verts. On vérifie donc d'abord que les fichiers sont là.
 */
test('A-010 — TEMOIN : le banc voit bien les fichiers de deploiement', function () {
    foreach (['Dockerfile.laravel', 'docker-compose.yml', 'docker-compose.prod.yml', 'infra/caddy/Caddyfile'] as $f) {
        $contenu = lireFichierDepot($f);
        expect(strlen($contenu))->toBeGreaterThan(200, "{$f} est vide ou tronqué");
    }
});

test('A-010 — aucune image ne sert HTTP par le serveur integre de PHP', function () {
    $dockerfile = lireFichierDepot('Dockerfile.laravel');

    // On ne cherche pas la chaîne dans les commentaires : seules les directives
    // exécutables comptent. Un commentaire qui EXPLIQUE le défaut corrigé est
    // souhaitable, et ne doit pas faire rougir la garde.
    $directives = array_values(array_filter(
        preg_split('/\R/', $dockerfile) ?: [],
        fn (string $ligne) => ! str_starts_with(ltrim($ligne), '#') && trim($ligne) !== ''
    ));

    foreach ($directives as $ligne) {
        expect($ligne)->not->toMatch(
            '/\b(CMD|ENTRYPOINT)\b.*php.*["\']?-S["\']?/i',
            "Cette ligne du Dockerfile sert HTTP par `php -S`, qui traite une requête à la fois :\n    {$ligne}"
        );
    }
});

test('A-010 — le service api demarre php-fpm, et le declare', function () {
    $dockerfile = lireFichierDepot('Dockerfile.laravel');

    // ⚠️ `expect()->toContain()` de Pest est VARIADIQUE : ses arguments sont
    // tous des valeurs à trouver, jamais un message. Un message passé en second
    // argument est donc cherché dans le fichier, et la garde rougit éternellement
    // — y compris une fois le correctif posé. Vingtième cas du patron A-011,
    // relevé le 2026-08-20 sur les gardes de cette branche elle-même.
    // `assertStringContainsString()` porte, lui, un vrai message d'échec.
    $this->assertStringContainsString(
        'CMD ["php-fpm", "-F"]',
        $dockerfile,
        'L\'étape `prod` doit démarrer php-fpm au premier plan.'
    );
    $this->assertStringContainsString(
        'EXPOSE 9000',
        $dockerfile,
        'php-fpm parle FastCGI sur 9000, pas HTTP sur 80 : le port déclaré doit le dire.'
    );
});

test('A-010 — le pool php-fpm tient au moins les dix utilisateurs du cahier des charges', function () {
    $pool = lireFichierDepot('infra/php/fpm-axion.conf');

    preg_match('/^\s*pm\.max_children\s*=\s*\$?\{?([A-Za-z0-9_:\-]+)\}?/m', $pool, $c);
    expect($c)->not->toBeEmpty('Le pool ne fixe pas `pm.max_children` : le réglage d\'usine est 5.');

    // La valeur peut être une variable d'environnement avec un défaut, forme
    // `${PHP_FPM_MAX_CHILDREN:-16}` : c'est le défaut qui nous intéresse.
    preg_match('/^\s*pm\.max_children\s*=\s*(?:\$\{[A-Za-z_]+:-)?(\d+)/m', $pool, $n);
    expect($n)->not->toBeEmpty('Impossible de lire une valeur numérique par défaut pour `pm.max_children`.');
    expect((int) $n[1])->toBeGreaterThanOrEqual(
        10,
        'Le principe directeur 8 du cahier des charges demande dix utilisateurs simultanés dès ' .
        'le premier jour. Un pool en dessous de dix les met en file d\'attente. ' .
        'Réglage d\'usine de l\'image Docker officielle : 5.'
    );

    $this->assertStringContainsString(
        'ping.path',
        $pool,
        'Sans point de vie, le contrôle de santé du conteneur ne peut rien mesurer.'
    );
});

test('A-010 — Caddy parle FastCGI a l api, plus HTTP', function () {
    $caddy = lireFichierDepot('infra/caddy/Caddyfile');

    $this->assertStringNotContainsString(
        'reverse_proxy api:80',
        $caddy,
        'L\'API n\'écoute plus en HTTP : un mandataire vers `api:80` renverrait 502.'
    );
    $this->assertStringContainsString(
        'api:9000',
        $caddy,
        'Le mandataire doit joindre php-fpm sur son port FastCGI.'
    );
    $this->assertStringContainsString(
        'transport fastcgi',
        $caddy,
        'Le transport doit être déclaré FastCGI.'
    );

    // Laravel n'a qu'un point d'entrée : toute requête doit y aboutir, sinon
    // FastCGI reçoit un `SCRIPT_FILENAME` qui n'existe pas et répond 404.
    expect($caddy)->toContain('rewrite * /index.php');
});

test('A-010 — le controle de sante n interroge plus un port HTTP disparu', function () {
    foreach (['Dockerfile.laravel', 'docker-compose.prod.yml', 'docker-compose.staging.yml'] as $f) {
        $contenu = lireFichierDepot($f);

        $lignes = array_values(array_filter(
            preg_split('/\R/', $contenu) ?: [],
            fn (string $l) => ! str_starts_with(ltrim($l), '#') && trim($l) !== ''
        ));

        foreach ($lignes as $ligne) {
            if (! preg_match('/curl.*http:\/\/localhost(:80)?\//i', $ligne)) {
                continue;
            }
            // Un contrôle de santé qui interroge en HTTP un conteneur devenu
            // FastCGI échoue toujours : le conteneur est déclaré malsain, et
            // le déploiement le redémarre en boucle.
            expect($ligne)->not->toMatch(
                '/healthcheck|test:|HEALTHCHECK|CMD-SHELL/i',
                "Contrôle de santé en HTTP sur un service qui ne sert plus HTTP, dans {$f} :\n    {$ligne}"
            );
        }
    }
});
