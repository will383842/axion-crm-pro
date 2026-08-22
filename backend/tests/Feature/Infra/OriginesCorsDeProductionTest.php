<?php

/**
 * GARDE : AUCUNE ORIGINE LOCALE DANS LE CORS DE PRODUCTION — constat F37-005 (S3).
 *
 * LE DÉFAUT.
 *
 * `backend/config/cors.php` portait, comme valeur par DÉFAUT :
 *
 *     'https://app.localhost,https://app.axion-crm-pro.com'
 *
 * avec `'supports_credentials' => true`. Un défaut n'est un défaut que si
 * quelque chose fournit l'autre valeur — et rien ne la fournissait. Mesure du
 * 2026-08-22 : une recherche de `CORS_ALLOWED_ORIGINS` sur TOUT le dépôt ne
 * rendait qu'une seule occurrence, celle de `cors.php` lui-même. Ni
 * `.env.example`, ni `docker-compose.prod.yml`, ni
 * `infra/scripts/configure-prod-env.sh`, ni `.github/workflows/deploy-direct-ssh.yml`
 * ne posaient la variable. **C'est donc le défaut qui s'appliquait en
 * production**, et l'API répondait `Access-Control-Allow-Origin:
 * https://app.localhost` avec les cookies.
 *
 * POURQUOI C'EST GRAVE, ET PAS SEULEMENT INÉLÉGANT.
 *
 * `app.localhost` n'appartient à personne : n'importe qui peut le faire résoudre
 * chez lui vers sa propre machine et s'y servir une page. Cette page devient
 * alors une origine autorisée de l'API de PRODUCTION, avec `credentials` — le
 * navigateur d'un utilisateur connecté y joint son cookie de session. C'est un
 * contournement complet de la protection d'origine, offert par une valeur
 * laissée là pour le confort du développement.
 *
 * LE SENS DU CORRECTIF, ET SON RISQUE.
 *
 * Le défaut est maintenant la seule origine de production. Le développement
 * local, qui reposait précisément sur ce défaut, reçoit la variable de
 * `docker-compose.local.yml` (surcouche jamais chargée en production) et de
 * `.env.example`. Le dernier test ci-dessous existe pour ça : retirer l'origine
 * locale du défaut SANS la poser côté local casse la console de développement,
 * et cette garde le dit avant que quelqu'un le découvre.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * Relit `config/cors.php` avec `CORS_ALLOWED_ORIGINS` RETIRÉE de l'environnement.
 *
 * ⚠️ On ne lit surtout pas `config('cors')` : le banc tourne avec la
 * configuration du banc. Ce qu'on veut mesurer, c'est ce que la PRODUCTION
 * obtient — c'est-à-dire le défaut, puisque la mesure du 2026-08-22 établit que
 * rien ne fournit la variable au conteneur de production.
 *
 * Retirer la variable des TROIS canaux (`$_SERVER`, `$_ENV`, `putenv`) est
 * nécessaire : `env()` les consulte tous, et n'en vider qu'un ferait mesurer une
 * présence tout en croyant mesurer une absence — le piège déjà payé par
 * `AucunSimulacreEnProductionTest`.
 *
 * @return array<string, mixed>
 */
function configurationCorsSansVariable(): array
{
    $cle = 'CORS_ALLOWED_ORIGINS';
    $ancien = $_SERVER[$cle] ?? null;

    unset($_SERVER[$cle], $_ENV[$cle]);
    putenv($cle);

    try {
        /** @var array<string, mixed> $configuration */
        $configuration = require config_path('cors.php');
    } finally {
        if ($ancien !== null) {
            $_SERVER[$cle] = $ancien;
            $_ENV[$cle] = $ancien;
            putenv("{$cle}={$ancien}");
        }
    }

    return $configuration;
}

/** Racine du dépôt vue depuis l'application Laravel. */
function racineDepotCors(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * TÉMOIN — une garde qui inspecte une liste vide ne trouve aucune origine
 * fautive et passe au vert. C'est le pire des verts.
 */
test('F37-005 — TEMOIN : le banc lit bien une liste d origines non vide', function () {
    $configuration = configurationCorsSansVariable();

    expect($configuration['allowed_origins'] ?? [])->not->toBeEmpty(
        "config/cors.php ne rend AUCUNE origine par defaut. Les deux assertions qui suivent "
        . "ne prouveraient alors rien du tout. Verifie que `require config_path('cors.php')` "
        . 'lit bien le fichier avant de croire ce vert.',
    );
});

test('F37-005 — le defaut de production n autorise aucune origine locale', function () {
    $configuration = configurationCorsSansVariable();
    /** @var list<string> $origines */
    $origines = array_values($configuration['allowed_origins']);

    $locales = array_values(array_filter(
        $origines,
        fn (string $origine): bool => str_contains($origine, 'localhost')
            || str_contains($origine, '127.0.0.1')
            || str_contains($origine, '.local')
            || str_contains($origine, '.test'),
    ));

    expect($locales)->toBe(
        [],
        "Le DEFAUT de `config/cors.php` autorise une origine locale : " . implode(', ', $locales) . ".\n\n"
        . "Ce defaut est ce que la PRODUCTION applique : mesure du 2026-08-22, rien dans le depot "
        . "ne pose `CORS_ALLOWED_ORIGINS` cote production (ni .env.example, ni "
        . "docker-compose.prod.yml, ni infra/scripts/configure-prod-env.sh, ni le workflow de "
        . "deploiement). Avec `supports_credentials => true`, une origine `.localhost` — que "
        . "n'importe qui peut faire resoudre vers sa propre machine — devient une origine de "
        . "confiance de l'API de production, cookies compris.\n\n"
        . 'GESTE : retire cette origine du DEFAUT et pose-la dans `docker-compose.local.yml` '
        . '(bloc `x-socle-local`), que la production ne charge jamais. Constat F37-005.',
    );
});

test('F37-005 — les identifiants ne sont jamais servis a une origine joker', function () {
    $configuration = configurationCorsSansVariable();
    /** @var list<string> $origines */
    $origines = array_values($configuration['allowed_origins']);

    // `Access-Control-Allow-Origin: *` avec `credentials` est refuse par les
    // navigateurs, mais `laravel/cors` reflete alors l'origine appelante : le
    // joker devient « toutes les origines », ce qui est bien pire que la panne.
    $joker = in_array('*', $origines, true) || $configuration['allowed_origins_patterns'] !== [];

    expect($joker)->toBeFalse(
        "`config/cors.php` combine `supports_credentials => true` avec une origine joker "
        . "(`*` ou un motif dans `allowed_origins_patterns`). Le paquet reflete alors l'origine "
        . "de l'appelant : TOUTE page du web devient une origine de confiance de l'API, avec le "
        . "cookie de session de l'utilisateur connecte.\n\n"
        . 'GESTE : enumere les origines une a une, ou passe `supports_credentials` a `false`. '
        . 'Constat F37-005.',
    );
});

test('F37-005 — la surcouche locale declare bien son origine, sinon le correctif casse le dev', function () {
    $surcouche = racineDepotCors() . '/docker-compose.local.yml';

    expect(file_exists($surcouche))->toBeTrue(
        "Le banc ne voit pas {$surcouche} : cette garde ne peut rien prouver sur la pile locale. "
        . 'Monte la racine du depot.',
    );

    $contenu = (string) file_get_contents($surcouche);

    // Cette assertion protege le CORRECTIF, pas le defaut : sans l'origine
    // locale ici, retirer `app.localhost` du defaut de production laisse la
    // console de developpement sans API, et quelqu'un remettra l'origine dans
    // le defaut — ce qui rejouerait F37-005 a l'identique.
    expect(str_contains($contenu, 'CORS_ALLOWED_ORIGINS: https://app.localhost'))->toBeTrue(
        "`docker-compose.local.yml` ne pose plus `CORS_ALLOWED_ORIGINS: https://app.localhost`. "
        . "Le defaut de `config/cors.php` etant desormais la seule origine de PRODUCTION, la "
        . "console locale n'a plus d'origine autorisee : chaque appel du SPA sera refuse par le "
        . "navigateur.\n\n"
        . 'GESTE : remets la ligne dans le bloc `x-socle-local` — et surtout PAS dans le defaut '
        . 'de `config/cors.php`, qui s applique a la production. Constat F37-005.',
    );
});
