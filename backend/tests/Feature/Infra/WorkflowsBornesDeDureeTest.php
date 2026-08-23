<?php

/**
 * GARDE : AUCUN JOB DE LA CHAINE QUALITE SANS BORNE DE DUREE — constat F38-008 (S2).
 *
 * CE QUI ETAIT MESURE.
 *
 * `grep -c timeout-minutes` rendait **0** pour les huit workflows enumeres
 * ci-dessous. Sans la cle, un job herite du plafond GitHub de six heures : au
 * moment de la mesure (2026-08-19), SIX executions de cette chaine etaient
 * figees entre 1 h 28 et 2 h 25, a consommer des minutes de runner sans que
 * personne ne soit prevenu. Un job gele ne rougit pas : il occupe la file, et
 * la PR qui l'attend reste « en cours » indefiniment.
 *
 * Ce n'etait pas une ignorance de la directive : le meme dossier s'en sert
 * ailleurs (`diag-website-status.yml` : 15, `prospection-collect.yml` : 180,
 * `prospection-find-websites.yml` : 340). Les workflows de QUALITE, eux, en
 * etaient tous depourvus.
 *
 * CE QUE CETTE GARDE MESURE, ET CE QU'ELLE NE MESURE PAS.
 *
 * Elle exige une borne sur chaque job, et refuse une borne si haute qu'elle ne
 * bornerait rien. Elle ne dit RIEN de la justesse de la valeur : une borne est
 * un plafond anti-gel, pas un budget de performance. Si un job legitime est tue
 * par sa borne, on la releve EN CITANT la duree du run tue — jamais au
 * jugement, jamais en retirant la cle.
 *
 * ⚠️ UN JOB QUI APPELLE UN WORKFLOW REUTILISABLE (`uses:`) EST EXEMPTE, ET CE
 * N'EST PAS UNE COMPLAISANCE : GitHub REFUSE `timeout-minutes` sur un tel job
 * (« Unexpected value 'timeout-minutes' »), le workflow ne se chargerait plus.
 * Les bornes des jobs du workflow appele s'appliquent a sa place — et `ci.yml`
 * est justement dans la liste ci-dessous.
 *
 * ⚠️ PIEGE DE BANC, deja documente par `DeploiementsDependentDeLaCiTest` : dans
 * le conteneur de tests, `/var/www/.github` n'est PAS un montage mais une COPIE
 * qui peut etre perimee. En CI (`actions/checkout`) la garde lit le vrai arbre.
 * En local, avant de croire un vert :
 *   docker cp .github/workflows/<fichier> a35r:/var/www/.github/workflows/
 */

use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

uses(TestCase::class);

function racineDepotF38(): string
{
    return realpath(base_path('..')) ?: base_path('..');
}

/**
 * Les huit workflows de la chaine qualite, nommes UN PAR UN.
 *
 * Pas de `glob` : un `glob` sur `.github/workflows/*.yml` ferait rougir demain
 * un workflow de prospection ajoute par quelqu'un d'autre, et la pente serait
 * alors d'assouplir la garde. Ce sont ces huit-la que le constat mesure.
 *
 * @return list<string>
 */
function workflowsQualiteF38(): array
{
    return [
        '.github/workflows/ci.yml',
        '.github/workflows/a11y.yml',
        '.github/workflows/security.yml',
        '.github/workflows/deploy-staging.yml',
        '.github/workflows/deploy-direct-ssh.yml',
        '.github/workflows/build-postgres-image.yml',
        '.github/workflows/surveillance-sauvegarde.yml',
        '.github/workflows/release-tracking.yml',
    ];
}

/**
 * Plafond du plafond, en minutes.
 *
 * Les six executions figees mesurees le 2026-08-19 duraient de 1 h 28 a 2 h 25.
 * Une borne posee au-dessus de deux heures ne les aurait donc PAS interrompues :
 * elle serait une cle presente et une garde absente. 120 est la limite haute
 * qu'aucun job de cette chaine n'a de raison d'atteindre.
 */
const PLAFOND_MAX_F38 = 120;

/**
 * Les jobs fautifs d'un workflow, avec la raison.
 *
 * @param  array<string, mixed>  $workflow
 * @return array{fautifs: list<string>, inspectes: int, exemptes: list<string>}
 */
function bornesDesJobsF38(array $workflow): array
{
    /** @var array<string, mixed> $jobs */
    $jobs = is_array($workflow['jobs'] ?? null) ? $workflow['jobs'] : [];

    $fautifs = [];
    $exemptes = [];
    $inspectes = 0;

    foreach ($jobs as $nom => $job) {
        $nom = (string) $nom;
        $job = is_array($job) ? $job : [];

        // Appel de workflow reutilisable : GitHub interdit la cle ici.
        if (isset($job['uses'])) {
            $exemptes[] = $nom;

            continue;
        }

        $inspectes++;
        $borne = $job['timeout-minutes'] ?? null;

        if ($borne === null) {
            $fautifs[] = $nom . ' — aucune borne : herite du plafond GitHub de 6 h';

            continue;
        }
        if (! is_int($borne) || $borne < 1) {
            $fautifs[] = $nom . ' — borne illisible : ' . var_export($borne, true);

            continue;
        }
        if ($borne > PLAFOND_MAX_F38) {
            $fautifs[] = $nom . ' — borne de ' . $borne . ' min : au-dessus de '
                . PLAFOND_MAX_F38 . ' min elle n aurait pas interrompu les gels mesures (1 h 28 a 2 h 25)';
        }
    }

    return ['fautifs' => $fautifs, 'inspectes' => $inspectes, 'exemptes' => $exemptes];
}

// ─────────────────────────────────────────────────────────────────────────────
// TEMOIN — l'analyse sait-elle seulement voir le defaut ?
// ─────────────────────────────────────────────────────────────────────────────

test('F38-008 — TEMOIN : l analyse voit un job sans borne, et n accuse pas un appel de workflow reutilisable', function () {
    $nu = Yaml::parse(<<<'YAML'
    jobs:
      sans-borne:
        runs-on: ubuntu-24.04
      trop-haute:
        runs-on: ubuntu-24.04
        timeout-minutes: 300
      correcte:
        runs-on: ubuntu-24.04
        timeout-minutes: 30
      appel-ci:
        uses: ./.github/workflows/ci.yml
    YAML);

    $vu = bornesDesJobsF38((array) $nu);

    expect($vu['fautifs'])->toHaveCount(2);
    expect($vu['fautifs'][0])->toStartWith('sans-borne');
    expect($vu['fautifs'][1])->toStartWith('trop-haute');
    // Le job `uses:` n'est ni fautif ni inspecte : il est hors de portee de la cle.
    expect($vu['exemptes'])->toBe(['appel-ci']);
    expect($vu['inspectes'])->toBe(3);
});

// ─────────────────────────────────────────────────────────────────────────────
// LA GARDE
// ─────────────────────────────────────────────────────────────────────────────

test('F38-008 — chaque job des huit workflows de qualite porte une borne de duree', function () {
    $racine = racineDepotF38();

    // TEMOIN DE NON-VACUITE. Sans lui, un chemin faux ou un repertoire non
    // monte rendrait ZERO fichier a inspecter et la garde passerait au vert
    // sans avoir rien lu — le pire des verts.
    $manquants = [];
    foreach (workflowsQualiteF38() as $relatif) {
        if (! is_file($racine . '/' . $relatif)) {
            $manquants[] = $relatif;
        }
    }
    $this->assertSame([], $manquants, sprintf(
        'Le banc ne voit pas ces workflows : %s. Une garde qui n a rien a inspecter '
        . 'passe au vert sans rien prouver. Racine lue : %s',
        implode(', ', $manquants),
        $racine,
    ));

    $fautifs = [];

    foreach (workflowsQualiteF38() as $relatif) {
        $workflow = (array) Yaml::parseFile($racine . '/' . $relatif);
        $vu = bornesDesJobsF38($workflow);

        // Second temoin de non-vacuite, PAR FICHIER : un workflow dont tous les
        // jobs seraient exemptes (ou qui n en declarerait aucun) rendrait une
        // liste vide de fautifs, donc un vert a vide.
        $this->assertGreaterThan(0, $vu['inspectes'], sprintf(
            '%s : aucun job inspectable (jobs exemptes : %s). La garde n aurait rien mesure '
            . 'sur ce fichier — verifier qu il declare bien des jobs `runs-on`.',
            $relatif,
            $vu['exemptes'] === [] ? '(aucun)' : implode(', ', $vu['exemptes']),
        ));

        foreach ($vu['fautifs'] as $faute) {
            $fautifs[] = $relatif . ' -> ' . $faute;
        }
    }

    $this->assertSame([], $fautifs, sprintf(
        "Ces jobs n ont pas de plafond anti-gel utilisable :\n  - %s\n\n"
        . "Sans `timeout-minutes`, un job herite du plafond GitHub de SIX HEURES. Mesure du\n"
        . "2026-08-19 (constat F38-008) : six executions de cette chaine etaient figees entre\n"
        . "1 h 28 et 2 h 25. Un job gele ne rougit pas — il occupe la file, et la PR qui\n"
        . "l attend reste « en cours » sans fin.\n\n"
        . "GESTE : ajouter la cle sous le `runs-on:` du job, calibree AU-DESSUS de la duree\n"
        . "utile observee et sous %d minutes :\n"
        . "  jobs:\n"
        . "    <job>:\n"
        . "      runs-on: ubuntu-24.04\n"
        . "      timeout-minutes: 30  # F38-008 — plafond anti-gel\n\n"
        . "Si c est un job legitime que sa borne a tue : la relever EN CITANT la duree du run\n"
        . 'tue — jamais au jugement, et jamais en retirant la cle.',
        implode("\n  - ", $fautifs),
        PLAFOND_MAX_F38,
    ));
});
