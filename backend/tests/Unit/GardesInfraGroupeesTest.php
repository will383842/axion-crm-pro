<?php

use Symfony\Component\Yaml\Yaml;

/**
 * GARDE : le job unique `gardes-infra` doit rougir quand UNE de ses commandes echoue.
 *
 * Un sous-shell `( ... ) || exit 1` neutralise `set -e` : le statut devient celui de la
 * derniere commande (un `echo OK`) et la garde passe au vert malgre l'echec.
 */
function etapesGardesInfra(): array
{
    $ci = Yaml::parseFile(dirname(__DIR__, 3) . '/.github/workflows/ci.yml');
    $job = $ci['jobs']['gardes-infra'] ?? null;
    expect($job)->not->toBeNull('Le job `gardes-infra` est absent de ci.yml.');

    return $job['steps'];
}

test('gardes-infra : aucun sous-shell teste par || ne neutralise set -e', function () {
    foreach (etapesGardesInfra() as $etape) {
        expect($etape['run'] ?? '')->not->toContain(') || exit');
    }
});

test('gardes-infra : seul le temoin Caddy desactive -e, les autres etapes gardent le shell par defaut', function () {
    foreach (etapesGardesInfra() as $etape) {
        if (isset($etape['shell'])) {
            expect($etape['name'])->toStartWith('TÉMOIN — ce contrôle sait-il');
        }
    }
});

test('gardes-infra : les gardes 2 et 3 rendent leur verdict meme si la precedente est rouge', function () {
    $parNom = [];
    foreach (etapesGardesInfra() as $etape) {
        $parNom[$etape['name'] ?? ''] = $etape;
    }
    expect($parNom['Construire le Caddy recompilé (cible caddy-bordure)']['if'] ?? null)->toBe('always()');
    expect($parNom["Les scripts d'infra sont-ils exécutables ?"]['if'] ?? null)->toBe('always()');
});
