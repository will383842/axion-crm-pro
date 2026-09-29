<?php

/**
 * GARDE — la suppression des étiquettes n'est JAMAIS automatique (2026-09-29).
 *
 * Ordre permanent de Will : « il est strictement interdit de purger quoi que
 * ce soit ». `crm:referentiels:reclasser` ne supprime une étiquette que sur
 * l'option `--supprimer-etiquettes-orphelines`, et cette option est une
 * DÉCISION de Will, tapée à la main. Aucun planificateur (`routes/console.php`,
 * `bootstrap/app.php`), aucun workflow GitHub, aucun script d'infrastructure,
 * aucune configuration ni aucun autre code ne doit la porter.
 *
 * Garde textuelle : elle cherche le nom de l'option dans tout ce qui peut
 * lancer une commande. Le seul fichier autorisé à le contenir est la commande
 * elle-même (sa signature et ses messages).
 */
const SJA_OPTION = 'supprimer-etiquettes-orphelines';

/** @return list<string> tous les fichiers texte qui peuvent lancer une commande */
function sjaFichiersLanceurs(): array
{
    $depot = dirname(__DIR__, 4);
    $dossiers = [
        $depot . '/.github',
        $depot . '/infra',
        $depot . '/backend/app',
        $depot . '/backend/routes',
        $depot . '/backend/bootstrap',
        $depot . '/backend/config',
        $depot . '/backend/database',
        $depot . '/workers/src',
    ];
    $fichiers = [];
    foreach ($dossiers as $dossier) {
        if (! is_dir($dossier)) {
            continue;
        }
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dossier, FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f instanceof SplFileInfo && $f->isFile() && str_contains($f->getPathname(), 'cache') === false
                && in_array(strtolower($f->getExtension()), ['php', 'yml', 'yaml', 'sh', 'json', 'conf', 'ts', 'js', 'cron', ''], true)) {
                $fichiers[] = $f->getPathname();
            }
        }
    }
    foreach (glob($depot . '/{docker-compose*.yml,Makefile,Dockerfile*}', GLOB_BRACE) ?: [] as $f) {
        $fichiers[] = $f;
    }

    return $fichiers;
}

test('l option de suppression n apparaît dans AUCUN planificateur, workflow, script ni autre code', function () {
    $fichiers = sjaFichiersLanceurs();
    $porteurs = [];
    foreach ($fichiers as $f) {
        if (str_contains((string) file_get_contents($f), SJA_OPTION)) {
            $porteurs[] = str_replace('\\', '/', substr($f, strlen(dirname(__DIR__, 4)) + 1));
        }
    }

    // Témoins : la sonde a lu les workflows ET la commande (qui, elle, porte
    // l'option) — sinon « personne ne la porte » ne prouverait rien.
    expect(count(array_filter($fichiers, static fn (string $f): bool => str_contains(str_replace('\\', '/', $f), '/.github/workflows/'))))->toBeGreaterThan(5)
        ->and($porteurs)->toBe(['backend/app/Console/Commands/CrmReferentielsReclasser.php']);
});

test('le planificateur ne lance pas le reclassement du tout', function () {
    $planif = (string) file_get_contents(dirname(__DIR__, 3) . '/routes/console.php')
        . (string) file_get_contents(dirname(__DIR__, 3) . '/bootstrap/app.php');

    // Témoin : on lit bien le planificateur (il planifie d'autres commandes).
    expect($planif)->toContain('schedule')
        ->and($planif)->not->toContain('crm:referentiels:reclasser');
});
