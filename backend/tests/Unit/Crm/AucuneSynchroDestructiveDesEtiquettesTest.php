<?php

/**
 * GARDE — aucun code ne REMPLACE en bloc les étiquettes d'une fiche
 * (chantier 2, 2026-09-29).
 *
 * `ClassifierService::autoTag()` faisait `$company->tags()->sync($tagIds)` :
 * `sync` DÉTACHE toutes les étiquettes qui ne sont pas dans la liste — les
 * manuelles, les `src:` de provenance, les verrouillées, dont celle qui
 * PROTÈGE les organisateurs d'événements et les fédérations. La classe n'était
 * appelée nulle part (audit du 2026-09-28) : le jour où quelqu'un l'aurait
 * rebranchée, elle aurait retiré la protection de toutes les fiches qu'elle
 * touchait. Elle a été SUPPRIMÉE.
 *
 * Toute synchro d'étiquettes du dépôt retire UNE à UNE ce qu'elle a posé, en
 * épargnant `src:`, verrouillées, manuelles (`AutoTaggerService::syncTags`,
 * `crm:referentiels:reclasser`). Cette garde refuse le retour du geste
 * destructeur sous n'importe quelle forme Eloquent : `sync`, `detach` sans
 * argument (tout retirer), `syncWithPivotValues`.
 */
function sdFichiersApp(): array
{
    $racine = dirname(__DIR__, 3) . '/app';
    $fichiers = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        if ($f instanceof SplFileInfo && $f->getExtension() === 'php') {
            $fichiers[] = $f->getPathname();
        }
    }

    return $fichiers;
}

/** Les gestes qui remplacent ou vident en bloc une relation d'étiquettes. */
const SD_MOTIF = '/tags\(\)\s*->\s*(sync|syncWithPivotValues)\s*\(|tags\(\)\s*->\s*detach\s*\(\s*\)/';

test('la sonde reconnaît les gestes destructeurs (témoin)', function () {
    expect(preg_match(SD_MOTIF, '$company->tags()->sync($tagIds);'))->toBe(1)
        ->and(preg_match(SD_MOTIF, '$c->tags() ->sync([])'))->toBe(1)
        ->and(preg_match(SD_MOTIF, '$c->tags()->syncWithPivotValues($ids, [])'))->toBe(1)
        ->and(preg_match(SD_MOTIF, '$c->tags()->detach()'))->toBe(1)
        // Ajouter sans rien retirer reste permis (`AutoTagApplier`).
        ->and(preg_match(SD_MOTIF, '$company->tags()->syncWithoutDetaching($ids);'))->toBe(0)
        // Retirer UNE étiquette désignée reste permis.
        ->and(preg_match(SD_MOTIF, '$company->tags()->detach($tagId);'))->toBe(0);
});

test('aucun fichier de app/ ne remplace ni ne vide en bloc les étiquettes d une fiche', function () {
    $fichiers = sdFichiersApp();
    $fautifs = [];
    foreach ($fichiers as $chemin) {
        if (preg_match(SD_MOTIF, (string) file_get_contents($chemin)) === 1) {
            $fautifs[] = $chemin;
        }
    }

    // Témoin : la sonde a bien lu le dépôt (sinon « aucun fautif » ne prouve rien).
    expect(count($fichiers))->toBeGreaterThan(100)
        ->and($fautifs)->toBe([]);
});

test('ClassifierService, qui le faisait, n existe plus', function () {
    expect(class_exists('App\\Services\\Classification\\ClassifierService'))->toBeFalse()
        ->and(is_file(dirname(__DIR__, 3) . '/app/Services/Classification/ClassifierService.php'))->toBeFalse();
});
