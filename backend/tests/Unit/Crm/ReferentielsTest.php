<?php

/**
 * RÉFÉRENTIELS UNIQUES — le calcul (chantier 1, 2026-09-28).
 *
 * Chaque attendu ci-dessous a été VÉRIFIÉ dans les tables de passage
 * (`resources/referentiels/*.csv`), pas deviné : c'est précisément l'intuition
 * « 52 = transport » (vraie en rév. 2, fausse en rév. 1) qui a mal rangé
 * 472 785 fiches.
 */

use App\Crm\Referentiels\Classement;
use App\Crm\Referentiels\EtiquettesClassement;
use App\Crm\Referentiels\NomenclatureNaf;
use App\Crm\Taxonomy;

// ── Chaque forme de code NAF ────────────────────────────────────────────────

test('NAF rév. 2 : lu dans la table des 732 sous-classes, code conservé', function () {
    $c = NomenclatureNaf::classer('62.01Z');

    expect($c->nomenclature)->toBe('naf_rev2')
        ->and($c->codeRev2)->toBe('62.01Z')
        ->and($c->secteur)->toBe('numerique_telecoms')
        ->and($c->methode)->toBe('rev2_table');
});

test('NAF rév. 2 : les divisions coupées en deux sont lues au GROUPE', function () {
    // 58.1 édition / 58.2 logiciel ; 66.1 finance / 66.2 assurance ;
    // 69.1 droit / 69.2 comptabilité.
    expect(NomenclatureNaf::secteur('58.11Z'))->toBe('edition_medias')
        ->and(NomenclatureNaf::secteur('58.29C'))->toBe('numerique_telecoms')
        ->and(NomenclatureNaf::secteur('66.12Z'))->toBe('banque_finance')
        ->and(NomenclatureNaf::secteur('66.22Z'))->toBe('assurance')
        ->and(NomenclatureNaf::secteur('69.10Z'))->toBe('droit')
        ->and(NomenclatureNaf::secteur('69.20Z'))->toBe('comptabilite_audit')
        // Télécoms (61) : oublié par les DEUX anciens classifieurs.
        ->and(NomenclatureNaf::secteur('61.10Z'))->toBe('numerique_telecoms')
        // Réparation (95) : l'enrichissement la rangeait en « associatif ».
        ->and(NomenclatureNaf::secteur('95.11Z'))->toBe('services_personne');
});

test('NAF rév. 1 présent dans la table de passage : converti en rév. 2', function () {
    $c = NomenclatureNaf::classer('52.1D');

    expect($c->nomenclature)->toBe('naf_rev1')
        ->and($c->codeRev2)->toBe('47.11D')
        ->and($c->methode)->toBe('rev1_table')
        // L'ancien 52 = commerce de DÉTAIL, pas le transport (52 en rév. 2).
        ->and($c->secteur)->toBe('commerce_detail');
});

test('NAF rév. 1 : les erreurs de lecture corrigées, une par une', function () {
    // Ancien 85 = santé (pas l'enseignement, 85 en rév. 2).
    expect(NomenclatureNaf::classer('85.1A')->codeRev2)->toBe('86.10Z')
        ->and(NomenclatureNaf::secteur('85.1A'))->toBe('sante')
        // Ancien 75 = administration (pas la santé vétérinaire).
        ->and(NomenclatureNaf::secteur('75.1A'))->toBe('administration_publique')
        // Ancien 80 = enseignement (pas les services aux entreprises).
        ->and(NomenclatureNaf::secteur('80.1Z'))->toBe('enseignement_formation')
        // Ancien 74.1A = activités juridiques.
        ->and(NomenclatureNaf::secteur('74.1A'))->toBe('droit')
        // Ancien 70.1A = promotion immobilière → 41.10A en rév. 2, donc BTP
        // selon la liste validée (division 41). La table officielle le dit ;
        // ce n'est PAS « immobilier ».
        ->and(NomenclatureNaf::classer('70.1A')->codeRev2)->toBe('41.10A')
        ->and(NomenclatureNaf::secteur('70.1A'))->toBe('btp');
});

test('NAF rév. 1 : le lien retenu suit la règle de construire.py (CC, « tout sauf », intitulé le plus proche)', function () {
    // La table INSEE donne plusieurs liens par code rév. 1. La première
    // version du script prenait la PREMIÈRE ligne : 74.1G (conseil) partait en
    // agriculture. Règle actuelle (resources/referentiels/LISEZMOI.md) :
    // candidats = liens CC, sinon liens sans précision, sinon tous ; parmi
    // eux, le lien « CC : tout sauf … », puis l'intitulé rév. 2 le plus
    // proche, puis le premier ; arbitrage manuel documenté pour 74.8K.
    // Attendus vérifiés contre le XLS INSEE.
    $cas = [
        '74.1G' => ['70.22Z', 'conseil_management'],
        '74.6Z' => ['80.10Z', 'services_entreprises'],
        '22.1A' => ['58.11Z', 'edition_medias'],
        '51.3A' => ['46.31Z', 'commerce_gros'],
        '93.0N' => ['96.09Z', 'services_personne'],
        '45.2U' => ['43.99D', 'btp'],
        '72.4Z' => ['63.12Z', 'numerique_telecoms'],
        '92.7C' => ['93.29Z', 'culture_sport_loisirs'],
        // Arbitrage manuel documenté.
        '74.8K' => ['82.99Z', 'services_entreprises'],
        // Plusieurs CC : le « tout sauf » / l'intitulé le plus proche l'emporte.
        '70.3E' => ['68.32B', 'immobilier'],
        // Aucun CC ni lien sans précision : seulement des liens CA.
        '15.9D' => ['11.01Z', 'agroalimentaire'],
    ];
    foreach ($cas as $code => [$rev2, $secteur]) {
        $c = NomenclatureNaf::classer($code);
        expect([$code, $c->codeRev2, $c->secteur, $c->methode])->toBe([$code, $rev2, $secteur, 'rev1_table']);
    }
});

test('NAF rév. 1 absent de la table : repli par GROUPE (72.2Z, 64.2B)', function () {
    $c = NomenclatureNaf::classer('72.2Z');

    expect($c->nomenclature)->toBe('naf_rev1')
        ->and($c->methode)->toBe('rev1_groupe')
        ->and($c->codeRev2)->toBeNull()
        ->and($c->secteur)->toBe('numerique_telecoms')
        // Ancien 64.2 = télécommunications — pas la finance (64 en rév. 2).
        ->and(NomenclatureNaf::classer('64.2B')->methode)->toBe('rev1_groupe')
        ->and(NomenclatureNaf::secteur('64.2B'))->toBe('numerique_telecoms');
});

test('NAF rév. 1 absent de la table et de son groupe : repli par DIVISION (51.6G)', function () {
    $c = NomenclatureNaf::classer('51.6G');

    expect($c->nomenclature)->toBe('naf_rev1')
        ->and($c->methode)->toBe('rev1_division')
        ->and($c->codeRev2)->toBeNull()
        ->and($c->secteur)->toBe('commerce_gros');
});

test('NAP 1973 (sans lettre) : lue dans la table NAP 600', function () {
    $c = NomenclatureNaf::classer('67.01');

    expect($c->nomenclature)->toBe('nap_1973')
        ->and($c->methode)->toBe('nap_table')
        ->and($c->codeRev2)->toBeNull()
        ->and($c->secteur)->toBe('restauration');
});

test('00.00Z, 00.0Z, 00.98 : aucune activité connue', function () {
    foreach (['00.00Z', '00.0Z', '00.98'] as $code) {
        $c = NomenclatureNaf::classer($code);
        expect($c->secteur)->toBe('non_classe')
            ->and($c->nomenclature)->toBe('inconnue')
            ->and($c->methode)->toBe('sans_activite');
    }
});

test('code vide ou absent : non classé, sans nomenclature', function () {
    foreach ([null, '', '   '] as $code) {
        $c = NomenclatureNaf::classer($code);
        expect($c->secteur)->toBe('non_classe')
            ->and($c->nomenclature)->toBeNull()
            ->and($c->codeRev2)->toBeNull();
    }
});

test('formes sans point : le point est réinséré avant la lecture', function () {
    expect(NomenclatureNaf::classer('6201Z')->codeRev2)->toBe('62.01Z')
        ->and(NomenclatureNaf::secteur('521D'))->toBe('commerce_detail')
        ->and(NomenclatureNaf::classer('6701')->nomenclature)->toBe('nap_1973');
});

test('les organisations professionnelles (NAF 94) restent non classées', function () {
    // Leur secteur utile est celui qu'elles REPRÉSENTENT (chantier 3).
    expect(NomenclatureNaf::secteur('94.11Z'))->toBe('non_classe')
        ->and(NomenclatureNaf::secteur('94.12Z'))->toBe('non_classe');
});

// ── Les tables et le référentiel ne divergent pas ─────────────────────────

test('secteurs.csv et Taxonomy::SECTEURS portent exactement les mêmes clés et libellés', function () {
    expect(NomenclatureNaf::secteursDuFichier())->toBe(Taxonomy::SECTEURS)
        ->and(count(Taxonomy::SECTEURS))->toBe(33);
});

test('chaque secteur cité par une table de passage existe dans le référentiel', function () {
    $inconnus = array_diff(NomenclatureNaf::secteursCites(), array_keys(Taxonomy::SECTEURS));

    expect($inconnus)->toBe([]);
});

test('aucun secteur des tables n est interprofessionnel : réservé aux fédérations', function () {
    expect(NomenclatureNaf::secteursCites())->not->toContain('interprofessionnel');
});

// ── Tailles ────────────────────────────────────────────────────────────────

test('taille depuis l INSEE : catégorie officielle d abord, puis tranche du siège', function () {
    expect(Classement::tailleDepuisInsee('00', 'GE'))->toBe('grand_groupe')
        ->and(Classement::tailleDepuisInsee('00', 'ETI'))->toBe('eti')
        ->and(Classement::tailleDepuisInsee('21', 'PME'))->toBe('pme')
        ->and(Classement::tailleDepuisInsee('02', 'PME'))->toBe('tpe')
        // Tranches seules, aux seuils INSEE (10, 250, 5 000).
        ->and(Classement::tailleDepuisInsee('03', null))->toBe('tpe')
        ->and(Classement::tailleDepuisInsee('11', null))->toBe('pme')
        ->and(Classement::tailleDepuisInsee('12', null))->toBe('pme')
        ->and(Classement::tailleDepuisInsee('32', null))->toBe('eti')
        ->and(Classement::tailleDepuisInsee('52', null))->toBe('grand_groupe')
        ->and(Classement::tailleDepuisInsee('NN', null))->toBe('tpe')
        ->and(Classement::tailleDepuisInsee(null, null))->toBe('tpe');
});

test('anciens vocabulaires de taille traduits : micro, grande, grande_entreprise', function () {
    expect(Classement::normaliserTaille('micro'))->toBe('tpe')
        ->and(Classement::normaliserTaille('grande'))->toBe('grand_groupe')
        ->and(Classement::normaliserTaille('grande_entreprise'))->toBe('grand_groupe')
        ->and(Classement::normaliserTaille('PME'))->toBe('pme')
        ->and(Classement::normaliserTaille('artisan'))->toBeNull()
        ->and(Classement::normaliserTaille(null))->toBeNull();
});

test('taille d une fiche SANS donnée INSEE : jamais « tpe » par défaut', function () {
    // Une CCI importée sans effectif ne devient pas une TPE.
    expect(Classement::taille(null, null, null))->toBeNull()
        ->and(Classement::taille(null, null, 'micro'))->toBe('tpe')
        ->and(Classement::taille('', '', 'grande'))->toBe('grand_groupe');
});

test('taille déclarée par le site : clés du formulaire et tranches du simulateur', function () {
    expect(Classement::tailleDeclaree('grande_entreprise'))->toBe('grand_groupe')
        ->and(Classement::tailleDeclaree('1'))->toBe('tpe')
        ->and(Classement::tailleDeclaree('6-10'))->toBe('tpe')
        ->and(Classement::tailleDeclaree('21-50'))->toBe('pme')
        ->and(Classement::tailleDeclaree('100+'))->toBe('pme')
        ->and(Classement::tailleDeclaree('n importe quoi'))->toBeNull();
});

// ── Natures ────────────────────────────────────────────────────────────────

test('nature : entreprise posée sur une fiche INSEE sans nature, et rien d autre', function () {
    expect(Classement::nature(null, 'insee'))->toBe('entreprise')
        ->and(Classement::nature('association', 'insee'))->toBe('association')
        ->and(Classement::nature(null, 'site'))->toBeNull()
        ->and(Classement::nature(null, null))->toBeNull();
});

test('le référentiel des natures ne contient pas « autre »', function () {
    // C'est la valeur fantôme que proposait le filtre de l'écran.
    expect(array_keys(Taxonomy::ENTITY_NATURES))->not->toContain('autre')
        ->and(array_keys(Taxonomy::ENTITY_NATURES))->toBe([
            'entreprise', 'association', 'cci', 'enseignement',
            'cabinet', 'institution', 'media', 'reseau',
            // Chantier 3 (2026-09-29) : organisations professionnelles.
            'federation',
        ]);
});

// ── Régions ────────────────────────────────────────────────────────────────

test('région : sigle, code ou libellé → code INSEE', function () {
    expect(Classement::region('AURA'))->toBe('84')
        ->and(Classement::region('IDF'))->toBe('11')
        ->and(Classement::region('PAC'))->toBe('93')
        ->and(Classement::region('84'))->toBe('84')
        ->and(Classement::region('6'))->toBe('06')
        ->and(Classement::region('auvergne-rhone-alpes'))->toBe('84')
        ->and(Classement::region('Provence-Alpes-Côte d\'Azur'))->toBe('93')
        ->and(Classement::region('Atlantide'))->toBeNull()
        ->and(Classement::region(''))->toBeNull();
});

test('la région rendue est TOUJOURS une chaîne, jamais l entier que PHP fabrique', function () {
    // `Taxonomy::REGIONS` a des clés « 84 » que PHP convertit en entiers.
    expect(Classement::region('Île-de-France'))->toBeString()->toBe('11')
        ->and(Classement::regionDuDepartement('38'))->toBeString()->toBe('84')
        ->and(Classement::regionDuDepartement('971'))->toBe('01')
        ->and(Classement::regionDuDepartement('2A'))->toBe('94');
});

test('pourFiche : une fiche étrangère ne reçoit pas la région d un département français', function () {
    $ro = Classement::pourFiche(['country_code' => 'RO', 'department_code' => '01', 'region_code' => null]);
    $fr = Classement::pourFiche(['country_code' => 'FR', 'department_code' => '01', 'region_code' => null]);

    expect($ro['region_code'])->toBeNull()
        ->and($fr['region_code'])->toBe('84');
});

// ── Étiquettes ────────────────────────────────────────────────────────────

test('étiquettes désirées : slugs et noms tirés du référentiel', function () {
    $tags = EtiquettesClassement::desirees('commerce_detail', 'grand_groupe', '84');

    expect($tags)->toBe([
        'sector-commerce-detail' => ['name' => 'Secteur : Commerce de détail', 'category' => 'sector'],
        'size-grand-groupe' => ['name' => 'Taille : Grand groupe', 'category' => 'size'],
        'region-84' => ['name' => 'Région : Auvergne-Rhône-Alpes', 'category' => 'geo'],
    ]);
});

test('secteur retenu : le NAF décide, sauf quand il ne dit rien et qu un secteur valide est posé', function () {
    expect(Classement::secteurRetenu('btp', 'interprofessionnel'))->toBe('btp')
        ->and(Classement::secteurRetenu('non_classe', 'interprofessionnel'))->toBe('interprofessionnel')
        ->and(Classement::secteurRetenu('non_classe', 'it_saas'))->toBe('non_classe')
        ->and(Classement::secteurRetenu('non_classe', null))->toBe('non_classe');
});

test('valeur à écrire : null garde la valeur posée, sauf une chaîne vide', function () {
    expect(Classement::valeurAEcrire(null, 'pme'))->toBe('pme')
        ->and(Classement::valeurAEcrire(null, ''))->toBeNull()
        ->and(Classement::valeurAEcrire('tpe', 'pme'))->toBe('tpe');
});

test('note de région d origine : ajoutée une seule fois', function () {
    $une = Classement::noteRegionDOrigine('Salon', 'England');

    expect($une)->toBe("Salon\nRégion d'origine : England")
        ->and(Classement::noteRegionDOrigine($une, 'England'))->toBe($une)
        ->and(Classement::noteRegionDOrigine(null, 'England'))->toBe("Région d'origine : England");
});
