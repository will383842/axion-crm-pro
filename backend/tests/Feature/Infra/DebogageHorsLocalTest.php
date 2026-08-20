<?php

/**
 * GARDE DU DEBOGAGE HORS POSTE DE DEVELOPPEMENT — constat F37-003 (S1).
 *
 * ── CE QUI A ETE MESURE, LE 2026-08-20 ──────────────────────────────────────
 *
 * 1. `docker-compose.staging.yml:125` posait `APP_DEBUG: 'true'`, avec ce
 *    commentaire : « Elle ne sert aucun public ». C'est FAUX, et le Caddyfile
 *    le dit noir sur blanc :
 *
 *      infra/caddy/Caddyfile:244   staging.axion-crm-pro.com { ... }
 *      infra/caddy/Caddyfile:279   staging-api.axion-crm-pro.com { ... }
 *
 *    Ces deux blocs sont servis par le Caddy de PRODUCTION, sur des noms
 *    publics, avec un certificat public. Aucun `basic_auth`, aucune liste
 *    d'adresses autorisees : les deux seules directives de restriction sont un
 *    `X-Robots-Tag: noindex` (qui parle aux moteurs, pas aux visiteurs) et
 *    `X-Frame-Options`. Il n'y a donc AUCUN filtre d'acces.
 *
 * 2. `infra/scripts/bootstrap-preprod.sh:96` ecrivait `regler APP_DEBUG true`
 *    dans le `.env` du serveur de preproduction. Site JUMEAU du precedent
 *    (patron A-011) : reparer l'un sans l'autre laisse la porte ouverte des le
 *    prochain amorcage.
 *
 * 3. `backend/config/app.php:6` lisait `(bool) env('APP_DEBUG', false)`.
 *    Mesure du defaut, dans un processus neuf :
 *
 *      $ env -u APP_DEBUG -u APP_ENV APP_ENV=production APP_DEBUG=true \
 *          php sonde-config.php
 *      debug=true
 *      env='production'
 *
 *    Rien n'empechait donc `APP_DEBUG=true` de prendre effet EN PRODUCTION.
 *
 * ── POURQUOI C'EST UN S1 ET PAS UNE COQUETTERIE ─────────────────────────────
 *
 * Une page de debogage Laravel n'est pas « une trace ». Elle affiche la valeur
 * de chaque variable d'environnement du processus — donc `DB_PASSWORD`,
 * `APP_KEY`, les jetons tiers — le contenu de la configuration resolue, la
 * requete SQL fautive avec ses parametres, et le code source des fichiers du
 * chemin d'appel. Une seule 500 provoquee suffit.
 *
 * Et provoquer une 500 est trivial sur une preproduction : c'est meme sa
 * raison d'etre.
 *
 * ── CE QUE CETTE GARDE MESURE ───────────────────────────────────────────────
 *
 * Elle ne se contente pas de relire les fichiers de configuration : elle
 * EXECUTE `config/app.php` dans un processus neuf, avec l'environnement d'une
 * production et d'une preproduction, et regarde ce qui en sort. Un fichier
 * peut mentir sur ce qu'il fait ; un processus, non.
 *
 * Elle porte son temoin positif (la sonde SAIT rendre `true` — sur un poste
 * local, ou le debogage reste legitime) et ses temoins de montage.
 */

use Tests\TestCase;

uses(TestCase::class);

/**
 * La racine du depot.
 *
 * ⚠️ `base_path('..')` et NON `base_path()` : `docker-compose.staging.yml` et
 * `infra/` vivent AU-DESSUS de l'application. Meme piege que
 * `PileDeProductionSansOverlayTest`.
 */
function racineDepotDebogage(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Ecrit (une seule fois) la sonde qui evalue `config/app.php` isolement.
 *
 * On evalue le FICHIER DE CONFIGURATION plutot que de demarrer l'application
 * complete : c'est `config/app.php` qui decide, et le demarrage complet
 * traverse des fournisseurs qui exigent une base de donnees et une cle. La
 * sonde reste ainsi rapide et sans effet de bord.
 */
function cheminSondeDebogage(): string
{
    $chemin = sys_get_temp_dir() . '/a35-sonde-debogage.php';

    $source = <<<'PHP'
    <?php
    // Sonde du constat F37-003 : que vaut `config('app.debug')` dans un
    // processus dont l'environnement est celui d'une production ?
    require __CHEMIN_AUTOLOAD__;
    $config = require __CHEMIN_CONFIG__;
    echo 'env=' . var_export($config['env'], true) . "\n";
    echo 'debug=' . var_export($config['debug'], true) . "\n";
    PHP;

    $source = str_replace(
        ['__CHEMIN_AUTOLOAD__', '__CHEMIN_CONFIG__'],
        [
            var_export(base_path('vendor/autoload.php'), true),
            var_export(base_path('config/app.php'), true),
        ],
        $source,
    );

    file_put_contents($chemin, $source);

    return $chemin;
}

/**
 * Joue la sonde avec l'environnement demande, dans un PROCESSUS NEUF.
 *
 * `env -u` retire d'abord les variables heritees du conteneur de mesure
 * (qui est bati sur la cible `dev` du Dockerfile et porte `APP_DEBUG=true`) :
 * sans ce retrait, la sonde mesurerait l'environnement du banc et non celui
 * qu'on lui demande.
 *
 * @param  array<string, string>  $environnement
 * @return array{code: int, sortie: string, debug: ?bool}
 */
function sondeDebogage(array $environnement): array
{
    $commande = 'env -u APP_ENV -u APP_DEBUG';
    foreach ($environnement as $cle => $valeur) {
        $commande .= ' ' . escapeshellarg($cle . '=' . $valeur);
    }
    $commande .= ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(cheminSondeDebogage()) . ' 2>&1';

    $lignes = [];
    $code = 0;
    exec($commande, $lignes, $code);
    $sortie = implode("\n", $lignes);

    $debug = null;
    if (preg_match('/^debug=(true|false)$/m', $sortie, $m) === 1) {
        $debug = $m[1] === 'true';
    }

    return ['code' => $code, 'sortie' => $sortie, 'debug' => $debug];
}

test('F37-003 — TEMOIN : la sonde demarre et sait rendre une valeur', function () {
    $resultat = sondeDebogage(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']);

    expect($resultat['code'])->toBe(
        0,
        "La sonde n'a pas pu s'executer. Tout ce qui suit serait un vert sans mesure.\n"
        . "Sortie :\n" . $resultat['sortie']
    );

    // TEMOIN POSITIF. Sans lui, une sonde cassee qui repondrait toujours
    // `false` ferait passer les gardes ci-dessous au vert sans rien prouver.
    // Sur un poste de developpement le debogage reste legitime : la sonde DOIT
    // y rendre `true`.
    expect($resultat['debug'])->toBeTrue(
        "La sonde ne rend plus `true` meme en local, avec APP_DEBUG=true. Elle ne mesure "
        . "donc plus rien, et les gardes qui suivent seraient vertes sur une panne.\n"
        . "Sortie :\n" . $resultat['sortie']
    );
});

test('F37-003 — APP_DEBUG=true ne prend PAS effet en production', function () {
    $resultat = sondeDebogage(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']);

    expect($resultat['debug'])->toBeFalse(
        "`config('app.debug')` vaut TRUE avec APP_ENV=production.\n\n"
        . "Une page de debogage Laravel affiche la valeur de CHAQUE variable d'environnement "
        . "du processus (DB_PASSWORD, APP_KEY, jetons tiers), la configuration resolue, la "
        . "requete SQL fautive avec ses parametres, et le code source du chemin d'appel. Une "
        . "seule 500 provoquee suffit a tout lire.\n\n"
        . "Correctif : `config/app.php` ne doit accorder le debogage qu'aux environnements "
        . "`local` et `testing`.\n\nSortie de la sonde :\n" . $resultat['sortie']
    );
});

test('F37-003 — APP_DEBUG=true ne prend PAS effet en preproduction', function () {
    $resultat = sondeDebogage(['APP_ENV' => 'staging', 'APP_DEBUG' => 'true']);

    expect($resultat['debug'])->toBeFalse(
        "`config('app.debug')` vaut TRUE avec APP_ENV=staging.\n\n"
        . "La preproduction N'EST PAS privee : `infra/caddy/Caddyfile` sert "
        . "`staging.axion-crm-pro.com` et `staging-api.axion-crm-pro.com` depuis le Caddy de "
        . "PRODUCTION, sur des noms publics, SANS basic_auth ni liste d'adresses autorisees. "
        . "Le seul en-tete de restriction est un `X-Robots-Tag: noindex`, qui parle aux "
        . "moteurs et a personne d'autre.\n\n"
        . "De plus la preproduction porte les memes secrets de forme que la production et une "
        . "copie de 300 000 fiches : ses pages de debogage sont exploitables telles quelles.\n\n"
        . "Sortie de la sonde :\n" . $resultat['sortie']
    );
});

test('F37-003 — le debogage reste possible sur un poste de developpement', function () {
    // Contre-garde : si le correctif etait ecrit trop large (par exemple un
    // `false` en dur), il volerait aux developpeurs l'outil qui leur sert tous
    // les jours, et quelqu'un finirait par le retirer en entier. On fige donc
    // aussi ce qui doit CONTINUER de marcher.
    expect(sondeDebogage(['APP_ENV' => 'local', 'APP_DEBUG' => 'true'])['debug'])->toBeTrue();
    expect(sondeDebogage(['APP_ENV' => 'testing', 'APP_DEBUG' => 'true'])['debug'])->toBeTrue();

    // Et un `APP_DEBUG` absent ou faux reste faux, meme en local.
    expect(sondeDebogage(['APP_ENV' => 'local', 'APP_DEBUG' => 'false'])['debug'])->toBeFalse();
    expect(sondeDebogage(['APP_ENV' => 'local'])['debug'])->toBeFalse();
});

/**
 * Les fichiers qui POSENT `APP_DEBUG` pour un environnement deploye.
 *
 * La correction de `config/app.php` suffit a rendre le defaut inoffensif. Ces
 * gardes-ci visent autre chose : un reglage faux qui dort reste un reglage
 * faux. Quelqu'un lira `APP_DEBUG: 'true'` dans l'overlay de preproduction et
 * en conclura que la preproduction affiche ses traces — il perdra une journee,
 * ou pire, il « reparera » `config/app.php` pour que ce soit vrai.
 *
 * @return list<string>
 */
function fichiersQuiPosentAppDebug(): array
{
    return [
        'docker-compose.staging.yml',
        'infra/scripts/bootstrap-preprod.sh',
    ];
}

test('F37-003 — TEMOIN : le banc voit les fichiers qui posent APP_DEBUG', function () {
    $manquants = [];
    foreach (fichiersQuiPosentAppDebug() as $relatif) {
        if (! is_file(racineDepotDebogage() . '/' . $relatif)) {
            $manquants[] = $relatif;
        }
    }

    // Sans ce temoin, un chemin faux ferait passer la garde suivante au vert
    // sur ZERO fichier — le pire des verts.
    expect($manquants)->toBe(
        [],
        'Le banc ne voit pas ces fichiers : ' . implode(', ', $manquants)
        . ". Une garde qui n'a rien a inspecter passe au vert sans rien prouver. "
        . "Racine vue : " . racineDepotDebogage()
    );
});

test('F37-003 — TEMOIN NEGATIF : le balayage SAIT reperer un APP_DEBUG vrai', function () {
    // Les trois ecritures rencontrees dans ce depot : YAML quote, YAML nu,
    // et l'appel de fonction shell de `bootstrap-preprod.sh`.
    foreach (["      APP_DEBUG: 'true'", '      APP_DEBUG: true', '  regler APP_DEBUG true', 'APP_DEBUG=true'] as $fabrique) {
        expect(poseAppDebugVrai($fabrique))->toBeTrue(
            "Le balayage ne reconnait pas « {$fabrique} » comme une pose a vrai : la garde "
            . 'ci-dessous passerait au vert sur le defaut lui-meme.'
        );
    }

    // Et il ne doit pas crier sur les formes correctes, sinon il rougirait sur
    // le correctif et la pente serait de l'assouplir.
    foreach (["      APP_DEBUG: 'false'", '  regler APP_DEBUG false', 'APP_DEBUG=false', '# APP_DEBUG=true dans le passe'] as $correcte) {
        expect(poseAppDebugVrai($correcte))->toBeFalse(
            "Le balayage crie sur « {$correcte} », qui est pourtant correcte."
        );
    }
});

/** Une ligne pose-t-elle `APP_DEBUG` a vrai ? (les commentaires sont ignores) */
function poseAppDebugVrai(string $ligne): bool
{
    $nue = trim($ligne);
    if ($nue === '' || str_starts_with($nue, '#')) {
        return false;
    }

    // `APP_DEBUG: 'true'` / `APP_DEBUG: true` / `APP_DEBUG=true` / `APP_DEBUG true`
    return preg_match('/APP_DEBUG\s*[:= ]\s*["\']?true["\']?\s*$/i', $nue) === 1;
}

test('F37-003 — aucun fichier de deploiement ne pose APP_DEBUG a vrai', function () {
    $fautifs = [];

    foreach (fichiersQuiPosentAppDebug() as $relatif) {
        $chemin = racineDepotDebogage() . '/' . $relatif;
        if (! is_file($chemin)) {
            continue; // le temoin de montage l'a deja dit
        }
        foreach (explode("\n", (string) file_get_contents($chemin)) as $i => $ligne) {
            if (poseAppDebugVrai($ligne)) {
                $fautifs[] = "{$relatif}:" . ($i + 1) . '  →  ' . trim($ligne);
            }
        }
    }

    expect($fautifs)->toBe(
        [],
        "Ces fichiers posent APP_DEBUG a vrai pour un environnement DEPLOYE.\n\n"
        . "`config/app.php` neutralise desormais la valeur hors `local`/`testing`, donc la "
        . "porte est fermee — mais un reglage faux qui dort reste un reglage faux : le "
        . "prochain lecteur croira que la preproduction affiche ses traces, et il « reparera » "
        . "`config/app.php` pour que ce soit vrai.\n\n"
        . "Poses fautives :\n  - " . implode("\n  - ", $fautifs)
    );
});
