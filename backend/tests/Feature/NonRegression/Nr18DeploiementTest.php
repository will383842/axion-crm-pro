<?php

/**
 * NR-18 — R11, le déploiement et la sauvegarde.
 *
 * - Les workflows de déploiement gardent `needs: [ci]` : rien ne part en
 *   production sans CI verte.
 * - La liste des workflows qui se connectent à un serveur (ceux qui lisent un
 *   secret `*_SSH_*`) est figée et ne peut que BAISSER (lot S11). Un nouveau
 *   workflow qui touche un serveur fait rougir tant qu’il n’est pas déclaré.
 * - La surveillance quotidienne de la sauvegarde existe et reste planifiée.
 *
 * Gardes existantes rattachées au groupe : `WorkflowsBornesDeDureeTest`,
 * `SurveillanceCanauxWorkflowTest`, `ImagesConstruitesSontDeployeesTest`.
 */

use Tests\Feature\NonRegression\Instantane;
use Tests\TestCase;

uses(TestCase::class);

function nr18Dossier(): string
{
    return base_path('../.github/workflows');
}

/** @return list<string> noms de fichiers */
function nr18Workflows(): array
{
    return array_map('basename', glob(nr18Dossier() . '/*.yml') ?: []);
}

function nr18Lire(string $fichier): string
{
    // Sans les lignes de commentaire YAML : une doc qui cite un secret n’est pas une lecture.
    $lignes = preg_split('/\R/', (string) file_get_contents(nr18Dossier() . '/' . $fichier)) ?: [];

    return implode("\n", array_filter($lignes, fn (string $l) => ! str_starts_with(ltrim($l), '#')));
}

test('NR-18 les workflows de déploiement attendent la CI (needs: [ci])', function () {
    foreach (['deploy-direct-ssh.yml', 'deploy-staging.yml'] as $fichier) {
        $texte = nr18Lire($fichier);
        expect(preg_match('/^\s+needs:\s*\[ci\]\s*$/m', $texte))->toBe(1, "{$fichier} doit garder « needs: [ci] ».");
        expect($texte)->toContain('uses: ./.github/workflows/ci.yml');
    }
});

test('NR-18 la liste des workflows qui se connectent à un serveur ne peut que baisser', function () {
    $touchent = array_values(array_filter(
        nr18Workflows(),
        fn (string $f) => preg_match('/secrets\.[A-Z_]*SSH[A-Z_]*/', nr18Lire($f)) === 1,
    ));

    Instantane::verifierContenuDans('nr18-workflows-serveur', $touchent);
    expect(count($touchent))->toBeGreaterThan(10); // témoin : la détection voit bien des workflows
});

test('NR-18 la surveillance de la sauvegarde existe et reste planifiée chaque jour', function () {
    $texte = nr18Lire('surveillance-sauvegarde.yml');

    expect($texte)->toMatch('/cron:\s*[\'"]0 5 \* \* \*[\'"]/');
});

test('NR-18 la surveillance des canaux reste planifiée chaque heure', function () {
    expect(nr18Lire('surveillance-canaux.yml'))->toMatch('/cron:\s*[\'"]17 \* \* \* \*[\'"]/');
});

test('NR-18 les 31 workflows sont tous connus (un nouveau workflow se déclare)', function () {
    Instantane::verifierContenuDans('nr18-workflows-tous', nr18Workflows());
    expect(count(nr18Workflows()))->toBeGreaterThanOrEqual(31);
});
