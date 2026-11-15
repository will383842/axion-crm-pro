<?php

/**
 * Garde d'environnement des scripts ouvriers (`tests/Support/ouvrier_*.php`) : hors `APP_ENV=testing`, ils s'arrêtent avec un
 * code non nul AVANT toute écriture (le démarrage de l'application, la lecture des arguments et les écritures viennent après).
 * Aucune base, aucun réseau : les arguments sont volontairement absents (la garde passe avant leur lecture).
 */
$ouvriers = ['ouvrier_cycle.php', 'ouvrier_injection.php', 'ouvrier_sequenceur.php', 'ouvrier_feu_vert.php'];

/** @return array{code: int, sortie: string, erreur: string} */
function ouvrierHorsTest(string $script, string $env): array
{
    $p = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/Support/' . $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 3), array_merge(getenv(), ['APP_ENV' => $env, 'ENVOI_REEL_ACTIF' => 'false']));
    expect(is_resource($p))->toBeTrue();
    $sortie = (string) stream_get_contents($pipes[1]);
    $erreur = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($p), 'sortie' => $sortie, 'erreur' => $erreur];
}

foreach ($ouvriers as $script) {
    foreach (['local', 'production', 'staging'] as $env) {
        test("{$script} refuse de tourner en environnement « {$env} » (code non nul, message, aucune sortie de résultat)", function () use ($script, $env) {
            $r = ouvrierHorsTest($script, $env);

            expect($r['code'])->not->toBe(0)
                ->and($r['erreur'])->toContain('APP_ENV=testing')
                ->and(trim($r['sortie']))->toBe('');
        });
    }
}

test('chaque script ouvrier appelle la garde avant de lire ses arguments ou d\'écrire', function () use ($ouvriers) {
    foreach ($ouvriers as $script) {
        $src = (string) file_get_contents(dirname(__DIR__, 2) . '/Support/' . $script);
        $garde = strpos($src, 'garde_environnement_ouvrier.php');
        expect($garde)->not->toBeFalse("{$script} n'appelle pas la garde");
        foreach (['[, $ws', 'microtime(true) <', 'DB::', '->bootstrap()'] as $apres) {
            $p = strpos($src, $apres);
            if ($p !== false) {
                expect($garde)->toBeLessThan($p, "{$script} : la garde doit précéder « {$apres} »");
            }
        }
    }
});
