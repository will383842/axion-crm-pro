<?php

/**
 * GARDE : LA CI JOUE LE GESTE, PAS SEULEMENT SA CAUSE — constat A06-005 (S3).
 *
 * CE QUI ETAIT MESURE, LE 2026-08-22.
 *
 * `ReconstructionBaseTest` interroge `pg_depend`, `ReconstructionAtteignableTest`
 * EMULE le `DROP` global de `db:wipe` sur une base temoin : deux mesures de la
 * CAUSE de B10-001, et de bonnes mesures. Mais **personne ne jouait le geste**.
 *
 *     grep -rn 'migrate:fresh' .github/workflows/   → aucune occurrence
 *     grep -rn 'db-rebuild'    .github/workflows/   → aucune occurrence
 *
 * Le seul endroit qui joue les deux passes, `make db-rebuild-check`, n est
 * appele par aucun workflow : il faut qu un humain y pense.
 *
 * POURQUOI DEUX FOIS, ET PAS UNE. Sur une base neuve, la premiere passe REUSSIT
 * — c est elle qui installe pg_partman. C est la SECONDE qui meurt en
 * SQLSTATE 2BP01 (« cannot drop table part_config because extension pg_partman
 * requires it »), parce que `db:wipe` s execute alors sur une base ou
 * l extension existe. Une CI qui ne jouerait `migrate:fresh` qu une fois
 * verdirait sur le cas qui ne casse pas.
 *
 * ⚠️ CE QUE CETTE GARDE NE FAIT PAS, ET C EST DELIBERE. Elle ne joue pas
 * `migrate:fresh` : la suite Pest partage UNE base, et un `migrate:fresh` lance
 * ici detruirait le travail des autres suites en cours. Elle verifie que le pas
 * existe **dans le workflow**, sur le conteneur `axion-ci-postgres` que le job
 * `backend` cree et detruit pour lui seul.
 *
 * ⚠️ PIEGE DE BANC, deja documente par `DeploiementsDependentDeLaCiTest` : dans
 * le conteneur de tests, `/var/www/.github` est une COPIE, pas un montage, et
 * elle peut etre perimee. En CI la garde lit le vrai arbre.
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function cheminCiA06(): string
{
    return (realpath(base_path('..')) ?: base_path('..')) . '/.github/workflows/ci.yml';
}

/**
 * Les pas du job `backend`, dans l ordre du fichier.
 *
 * @return list<array<string, mixed>>
 */
function pasDuJobBackendA06(): array
{
    /** @var array<string, mixed> $ci */
    $ci = (array) Yaml::parseFile(cheminCiA06());
    /** @var array<string, mixed> $jobs */
    $jobs = is_array($ci['jobs'] ?? null) ? $ci['jobs'] : [];
    /** @var list<array<string, mixed>> $pas */
    $pas = is_array($jobs['backend']['steps'] ?? null) ? array_values($jobs['backend']['steps']) : [];

    return $pas;
}

/**
 * Compte les invocations REELLES de `migrate:fresh` dans un script shell —
 * les lignes commentees ne comptent pas. Une garde qui compterait un
 * commentaire certifierait ce qu elle n inspecte pas.
 */
function invocationsMigrateFreshA06(string $script): int
{
    $lignes = preg_split('/\R/', $script) ?: [];
    $total = 0;

    foreach ($lignes as $ligne) {
        if (str_starts_with(ltrim($ligne), '#')) {
            continue;
        }
        $total += preg_match_all('/artisan\s+migrate:fresh/', $ligne);
    }

    return $total;
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — l analyse sait-elle compter ce qu elle pretend compter ?
// ─────────────────────────────────────────────────────────────────────────────

test('A06-005 — TEMOIN : le comptage ignore les commentaires et exige deux invocations reelles', function () {
    $commente = <<<'SH'
    # php artisan migrate:fresh --force
    echo "rien"
    SH;
    $uneSeule = <<<'SH'
    php artisan migrate:fresh --force
    SH;
    $deux = <<<'SH'
    php artisan migrate:fresh --force
    php artisan migrate:fresh --force
    SH;

    expect(invocationsMigrateFreshA06($commente))->toBe(0);
    expect(invocationsMigrateFreshA06($uneSeule))->toBe(1);
    expect(invocationsMigrateFreshA06($deux))->toBe(2);
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE
// ─────────────────────────────────────────────────────────────────────────────

test('A06-005 — la CI reconstruit la base deux fois de suite, apres la suite Pest', function () {
    $pas = pasDuJobBackendA06();

    // TEMOIN DE NON-VACUITE : sans pas lus, tout ce qui suit serait un vert a vide.
    $this->assertNotEmpty($pas, sprintf(
        'Le job `backend` de %s ne declare aucun pas. La garde A06-005 n a rien inspecte : '
        . 'verifier le chemin lu (dans le conteneur, .github est une COPIE, pas un montage).',
        cheminCiA06(),
    ));

    $rangPest = null;
    $rangFresh = null;
    $invocations = 0;

    foreach ($pas as $rang => $etape) {
        $script = (string) ($etape['run'] ?? '');
        $nom = (string) ($etape['name'] ?? '');

        if ($rangPest === null && str_starts_with($nom, 'Pest')) {
            $rangPest = $rang;
        }

        $compte = invocationsMigrateFreshA06($script);
        if ($compte > 0 && $rangFresh === null) {
            $rangFresh = $rang;
            $invocations = $compte;
        }
    }

    $this->assertNotNull($rangPest, 'Aucun pas « Pest » dans le job `backend` : la garde ne peut plus situer la reconstruction par rapport a la suite.');

    $this->assertNotNull($rangFresh, sprintf(
        "Aucun pas du job `backend` ne joue `php artisan migrate:fresh` (constat A06-005).\n\n"
        . "Les gardes existantes mesurent la CAUSE (pg_depend, emulation du DROP) ; aucune ne\n"
        . "joue LE GESTE, et `make db-rebuild-check` n est appele par aucun workflow. Une base\n"
        . "qu on ne reconstruit jamais est une base dont on ne sait pas si elle se reconstruit.\n\n"
        . "GESTE : dans %s, job `backend`, APRES le pas Pest, un pas qui joue deux fois\n"
        . "`php artisan migrate:fresh --force` sur `axion-ci-postgres` — le conteneur que le job\n"
        . 'cree et detruit pour lui seul, JAMAIS une base partagee.',
        cheminCiA06(),
    ));

    $this->assertGreaterThanOrEqual(2, $invocations, sprintf(
        "Le pas de reconstruction ne joue `migrate:fresh` que %d fois.\n\n"
        . "Une seule passe verdit sur le cas qui ne casse pas : sur une base neuve c est la\n"
        . "PREMIERE qui installe pg_partman, et la SECONDE qui meurt en SQLSTATE 2BP01\n"
        . "(« cannot drop table part_config because extension pg_partman requires it »).\n"
        . "C est le critere de `make db-rebuild-check`, et c est le seul qui prouve quelque\n"
        . 'chose (constats A06-005 / B10-001).',
        $invocations,
    ));

    $this->assertGreaterThan($rangPest, $rangFresh, sprintf(
        "Le pas de reconstruction (rang %d) precede le pas Pest (rang %d).\n\n"
        . "`migrate:fresh` DETRUIT la base : joue avant, il ferait tourner toute la suite sur\n"
        . 'une base a moitie reconstruite. Le remettre APRES le pas Pest.',
        $rangFresh,
        $rangPest,
    ));
});
