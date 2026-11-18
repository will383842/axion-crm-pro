<?php

/**
 * GARDE DU BOUTON DE RATTRAPAGE C3 — à la main seulement, à blanc par défaut.
 */

use Tests\TestCase;

uses(TestCase::class);

function texteWorkflowRattrapage(): string
{
    $chemin = (realpath(base_path('..')) ?: base_path('..'))
        . '/.github/workflows/crm-rattrapage-dossiers.yml';

    return (string) file_get_contents($chemin);
}

/** Le texte sans les lignes de commentaire. */
function codeWorkflowRattrapage(): string
{
    return implode("\n", array_filter(
        explode("\n", texteWorkflowRattrapage()),
        fn (string $l): bool => ! str_starts_with(ltrim($l), '#'),
    ));
}

it('ne se lance jamais seul : ni schedule ni push', function () {
    $code = codeWorkflowRattrapage();
    expect($code)->toContain('workflow_dispatch:')
        ->and($code)->not->toMatch('/^\s*schedule\s*:/m')
        ->and($code)->not->toMatch('/^\s*push\s*:/m')
        ->and($code)->not->toMatch('/^\s*pull_request\s*:/m')
        ->and($code)->not->toContain('cron:');
});

it('est à blanc par défaut', function () {
    expect(codeWorkflowRattrapage())
        ->toMatch('/mode:\s*\n(?:\s+.*\n)*?\s+default:\s*a-blanc\b/')
        ->and(codeWorkflowRattrapage())->not->toMatch('/default:\s*appliquer/');
});

it('exige la confirmation APPLIQUER avant toute connexion', function () {
    $code = codeWorkflowRattrapage();
    // La ligne entière ET la sortie en erreur : retirer `exit 1` ou ajouter une
    // condition qui affaiblit le test doit rougir.
    expect($code)->toMatch('/if \[ "\$CONFIRMATION" != "APPLIQUER" \]; then\s*\n[^\n]*::error::[^\n]*\n\s*exit 1\s*\n\s*fi/');
    expect(strpos($code, '"$CONFIRMATION" != "APPLIQUER"'))
        ->toBeLessThan(strpos($code, 'webfactory/ssh-agent'));
});

it('n\'ajoute --appliquer que pour le mode appliquer, et un seul à la fois', function () {
    $code = codeWorkflowRattrapage();
    expect($code)->toContain('if [ "$MODE" = "appliquer" ]; then OPT="--appliquer"; fi')
        ->and(substr_count($code, '--appliquer'))->toBe(1)
        ->and($code)->toContain('concurrency:');
});
