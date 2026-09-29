<?php

/**
 * LA RÈGLE DE NOMMAGE DES ÉTIQUETTES (chantier 2, 2026-09-29) —
 * `App\Crm\Etiquettes\FamillesEtiquettes`.
 *
 * Toute étiquette que le CODE produit a une famille, et la catégorie de cette
 * famille. Une étiquette « sans famille » ne peut naître que d'une saisie hors
 * règle : l'inventaire la compte, l'automate n'en fabrique jamais.
 */

use App\Crm\Etiquettes\FamillesEtiquettes;
use App\Crm\Federations\EtiquettesFederation;
use App\Crm\Referentiels\EtiquettesClassement;
use App\Crm\Referentiels\Metiers;
use App\Crm\Taxonomy;
use Database\Seeders\GovernedTagsSeeder;

test('les quatre types de famille, et le reste', function () {
    expect(FamillesEtiquettes::famille('src:scraping-evenements-pro', 'auto'))->toBe(['type' => 'gouvernee', 'famille' => 'src'])
        ->and(FamillesEtiquettes::famille('famille:ordre', 'auto'))->toBe(['type' => 'gouvernee', 'famille' => 'famille'])
        ->and(FamillesEtiquettes::famille('metier-coiffeurs', 'auto'))->toBe(['type' => 'automatique', 'famille' => 'metier'])
        ->and(FamillesEtiquettes::famille('dept-38', 'auto'))->toBe(['type' => 'automatique', 'famille' => 'dept'])
        ->and(FamillesEtiquettes::famille('cible-chaude', 'llm'))->toBe(['type' => 'ia', 'famille' => 'ia'])
        // L'IA reste l'IA, même si le modèle propose un slug de famille.
        ->and(FamillesEtiquettes::famille('size-matters', 'llm'))->toBe(['type' => 'ia', 'famille' => 'ia'])
        // Une manuelle reste manuelle, même nommée comme une automatique.
        ->and(FamillesEtiquettes::famille('sector-it-saas', 'manual'))->toBe(['type' => 'manuelle', 'famille' => null])
        // Le reste : sans famille.
        ->and(FamillesEtiquettes::famille('decisionnaire', 'auto'))->toBe(['type' => 'sans_famille', 'famille' => null])
        ->and(FamillesEtiquettes::famille('inconnu:truc', 'auto'))->toBe(['type' => 'sans_famille', 'famille' => null])
        ->and(FamillesEtiquettes::famille('sector-', 'auto'))->toBe(['type' => 'sans_famille', 'famille' => null]);
});

test('toute étiquette de classement a une famille automatique ET la catégorie de sa famille', function () {
    $desirees = [];
    foreach (array_keys(Taxonomy::SECTEURS) as $secteur) {
        $desirees += EtiquettesClassement::desirees($secteur, null, null, null);
    }
    foreach (array_keys(Taxonomy::TAILLES) as $taille) {
        $desirees += EtiquettesClassement::desirees(null, $taille, null, null);
    }
    foreach (array_keys(Taxonomy::REGIONS) as $region) {
        $desirees += EtiquettesClassement::desirees(null, null, (string) $region, null);
    }
    foreach (array_keys(Metiers::table()) as $code) {
        $desirees += EtiquettesClassement::desirees(null, null, null, $code);
    }

    expect(count($desirees))->toBe(count(Taxonomy::SECTEURS) + count(Taxonomy::TAILLES) + count(Taxonomy::REGIONS) + count(Metiers::liste()));
    foreach ($desirees as $slug => $spec) {
        expect(FamillesEtiquettes::famille($slug, 'auto')['type'])->toBe('automatique', "sans famille : {$slug}")
            ->and(FamillesEtiquettes::categorieAttendue($slug, 'auto'))->toBe($spec['category'], "catégorie : {$slug}");
    }
});

test('toute étiquette de fédération est gouvernée, dans la catégorie de son namespace', function () {
    $ligne = (object) [
        'famille' => 'ordre', 'niveau' => 'national', 'secteurs' => '{droit,comptabilite_audit}',
        'tailles_adherents' => '{tpe}', 'pertinence' => 'haute', 'contactabilite' => 'email_verifie',
    ];
    $tags = EtiquettesFederation::desirees($ligne);

    expect($tags)->not->toBe([]);
    foreach ($tags as $slug => $spec) {
        expect(FamillesEtiquettes::famille($slug, 'auto')['type'])->toBe('gouvernee', "sans famille : {$slug}")
            ->and(FamillesEtiquettes::categorieAttendue($slug, 'auto'))->toBe($spec['category'], "catégorie : {$slug}");
    }
});

test('tout le référentiel gouverné (seeder) est gouverné, dans la catégorie de son namespace', function () {
    foreach (GovernedTagsSeeder::referential() as $slug => $spec) {
        expect(FamillesEtiquettes::famille($slug, 'auto')['type'])->toBe('gouvernee', "sans famille : {$slug}")
            ->and(FamillesEtiquettes::categorieAttendue($slug, 'auto'))->toBe($spec['category'], "catégorie : {$slug}");
    }
});

test('les catégories des familles existent toutes dans le CHECK de tags.category', function () {
    foreach (FamillesEtiquettes::AUTOMATIQUES as $categorie) {
        expect(in_array($categorie, Taxonomy::TAG_CATEGORIES, true))->toBeTrue($categorie);
    }
    expect(in_array(FamillesEtiquettes::CATEGORIE_IA, Taxonomy::TAG_CATEGORIES, true))->toBeTrue()
        // Le préfixe tenu par le classement est bien une famille déclarée.
        ->and(array_diff(EtiquettesClassement::PREFIXES, array_keys(FamillesEtiquettes::AUTOMATIQUES)))->toBe([]);
});
