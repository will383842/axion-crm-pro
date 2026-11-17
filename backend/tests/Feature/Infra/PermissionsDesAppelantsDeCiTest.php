<?php

use Symfony\Component\Yaml\Yaml;

/**
 * Un workflow appelé (`uses: ./.github/workflows/ci.yml`) ne peut pas demander
 * plus de permissions que son appelant : sinon GitHub refuse tout le run
 * (« startup_failure »). Chaque appelant doit donc couvrir ce que demandent
 * les jobs de `ci.yml`.
 */
function permissionsDeCi(string $racine): array
{
    $ci = Yaml::parse(file_get_contents($racine . '/ci.yml'));
    $demandees = [];
    foreach (($ci['jobs'] ?? []) as $job) {
        foreach (($job['permissions'] ?? []) as $nom => $niveau) {
            $demandees[$nom] = max($demandees[$nom] ?? 0, $niveau === 'write' ? 2 : 1);
        }
    }
    foreach (($ci['permissions'] ?? []) as $nom => $niveau) {
        $demandees[$nom] = max($demandees[$nom] ?? 0, $niveau === 'write' ? 2 : 1);
    }

    return $demandees;
}

it('chaque appelant de ci.yml accorde au moins les permissions que ci.yml demande', function () {
    $racine = dirname(__DIR__, 4) . '/.github/workflows';
    $demandees = permissionsDeCi($racine);
    expect($demandees)->not->toBeEmpty();

    $appelants = 0;
    foreach (glob($racine . '/*.yml') as $fichier) {
        $wf = Yaml::parse(file_get_contents($fichier));
        foreach (($wf['jobs'] ?? []) as $id => $job) {
            if (($job['uses'] ?? null) !== './.github/workflows/ci.yml') {
                continue;
            }
            $appelants++;
            $accordees = $job['permissions'] ?? ($wf['permissions'] ?? []);
            foreach ($demandees as $nom => $niveau) {
                $obtenu = ($accordees[$nom] ?? 'none') === 'write' ? 2 : (($accordees[$nom] ?? 'none') === 'read' ? 1 : 0);
                expect($obtenu)->toBeGreaterThanOrEqual($niveau, basename($fichier) . " job {$id} : permission `{$nom}` manquante");
            }
        }
    }
    expect($appelants)->toBeGreaterThanOrEqual(2);
});
