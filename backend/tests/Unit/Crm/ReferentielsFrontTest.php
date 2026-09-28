<?php

/**
 * GARDE — la copie frontend des référentiels est À JOUR.
 *
 * `frontend/src/lib/referentiels.generated.ts` est GÉNÉRÉ depuis `Taxonomy`
 * (`php artisan crm:referentiels:generer-front`). Avant le 2026-09-28 ces
 * listes étaient recopiées à la main dans cinq écrans, et avaient divergé
 * (filtre Nature avec « Autres », audiences à 10 secteurs, tailles `micro`…).
 *
 * Si ce test rougit : un référentiel a bougé côté serveur sans que le fichier
 * soit régénéré — lancer la commande, et versionner le fichier produit.
 */

use App\Crm\Referentiels\ExportFront;
use App\Crm\Taxonomy;

test('le fichier généré pour l écran est identique à ce que produit le référentiel', function () {
    $chemin = ExportFront::chemin();

    expect(is_file($chemin))->toBeTrue();
    $versionne = str_replace("\r\n", "\n", (string) file_get_contents($chemin));

    $this->assertSame(
        ExportFront::contenu(),
        $versionne,
        'frontend/src/lib/referentiels.generated.ts n\'est plus à jour : '
        . 'lancer `php artisan crm:referentiels:generer-front` depuis backend/.',
    );
});

test('le fichier généré porte toutes les valeurs de chaque référentiel', function () {
    // Témoin : sans lui, un générateur qui rendrait une chaîne vide serait
    // « identique » à un fichier vide, et la garde ci-dessus resterait verte.
    $contenu = ExportFront::contenu();

    foreach ([
        Taxonomy::SECTEURS, Taxonomy::TAILLES, Taxonomy::ENTITY_NATURES, Taxonomy::REGIONS,
        Taxonomy::FEDERATION_FAMILLES, Taxonomy::FEDERATION_NIVEAUX, Taxonomy::FEDERATION_CONTACTABILITES,
        Taxonomy::FEDERATION_CERTITUDES, Taxonomy::FEDERATION_PERTINENCES, Taxonomy::FEDERATION_PARTENARIATS,
    ] as $liste) {
        foreach (array_keys($liste) as $code) {
            expect($contenu)->toContain('{ code: "' . $code . '"');
        }
    }
    expect($contenu)->not->toContain('"autre"')
        ->and($contenu)->not->toContain('"grande_entreprise"');
});
