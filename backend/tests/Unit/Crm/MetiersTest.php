<?php

/**
 * MÉTIERS — la table sous-classe NAF rév. 2 → métier (chantier 2, 2026-09-29).
 *
 * Deux familles de gardes :
 *  - la COHÉRENCE des deux fichiers versionnés (`metiers.csv`,
 *    `naf_rev2_metiers.csv`) entre eux et avec la NAF rév. 2 officielle
 *    (`naf_rev2_secteurs.csv`, source INSEE) ;
 *  - la CORRESPONDANCE sur des exemples réels, dont les sous-classes qui NE
 *    DOIVENT PAS avoir de métier (aucune invention).
 */

use App\Crm\Referentiels\EtiquettesClassement;
use App\Crm\Referentiels\Metiers;
use App\Crm\Referentiels\NomenclatureNaf;

/** @return list<list<string>> lignes d'un CSV du référentiel, en-tête retiré */
function mtCsv(string $fichier): array
{
    $flux = fopen(dirname(__DIR__, 3) . '/resources/referentiels/' . $fichier, 'rb');
    expect($flux)->not->toBeFalse();
    $lignes = [];
    fgetcsv($flux, 0, ',', '"', '');
    while (($l = fgetcsv($flux, 0, ',', '"', '')) !== false) {
        if ($l !== [null]) {
            $lignes[] = array_map(static fn (?string $v): string => (string) $v, $l);
        }
    }
    fclose($flux);

    return $lignes;
}

test('metiers.csv : des clés uniques, en minuscules et tirets, chacune avec un libellé', function () {
    $cles = array_column(mtCsv('metiers.csv'), 0);

    expect($cles)->toHaveCount(count(array_unique($cles)))
        ->and(count($cles))->toBeGreaterThanOrEqual(55);
    foreach (mtCsv('metiers.csv') as [$cle, $libelle]) {
        expect(preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $cle))->toBe(1, "clé mal formée : {$cle}")
            ->and(trim($libelle))->not->toBe('');
    }
});

test('naf_rev2_metiers.csv : chaque code est une sous-classe NAF rév. 2 officielle, rangée dans UN seul métier connu', function () {
    $officielles = [];
    foreach (mtCsv('naf_rev2_secteurs.csv') as $l) {
        $officielles[$l[0]] = $l[2];
    }
    $metiers = array_column(mtCsv('metiers.csv'), 1, 0);
    $vus = [];
    foreach (mtCsv('naf_rev2_metiers.csv') as [$code, $metier, $intitule]) {
        expect(array_key_exists($code, $officielles))->toBeTrue("{$code} n'est pas une sous-classe NAF rév. 2")
            // L'intitulé est celui de l'INSEE, recopié — jamais réécrit à la main.
            ->and($intitule)->toBe($officielles[$code])
            ->and(array_key_exists($metier, $metiers))->toBeTrue("métier inconnu : {$metier}")
            ->and(isset($vus[$code]))->toBeFalse("{$code} rangé deux fois");
        $vus[$code] = true;
    }
});

test('chaque métier a au moins une sous-classe, et la classe lit exactement les fichiers', function () {
    foreach (array_keys(Metiers::liste()) as $cle) {
        expect(Metiers::sousClasses($cle))->not->toBe([], "métier sans sous-classe : {$cle}");
    }
    expect(Metiers::liste())->toBe(array_column(mtCsv('metiers.csv'), 1, 0))
        ->and(Metiers::table())->toBe(array_column(mtCsv('naf_rev2_metiers.csv'), 1, 0));
});

test('correspondance sur des sous-classes réelles', function (string $naf, string $metier) {
    expect(Metiers::pourNafRev2($naf))->toBe($metier);
})->with([
    'activités juridiques' => ['69.10Z', 'professions-juridiques'],
    'activités comptables' => ['69.20Z', 'experts-comptables'],
    'médecins généralistes' => ['86.21Z', 'medecins'],
    'pratique dentaire' => ['86.23Z', 'dentistes'],
    'pharmacies' => ['47.73Z', 'pharmacies'],
    'infirmiers' => ['86.90D', 'infirmiers-sages-femmes'],
    'kinésithérapeutes' => ['86.90E', 'kines-reeducation'],
    'architectes' => ['71.11Z', 'architectes'],
    'géomètres' => ['71.12A', 'geometres'],
    'agences immobilières' => ['68.31Z', 'agents-immobiliers'],
    'garages' => ['45.20A', 'garages-carrosseries'],
    'coiffure' => ['96.02A', 'coiffeurs'],
    'soins de beauté' => ['96.02B', 'esthetique'],
    'boulangerie-pâtisserie' => ['10.71C', 'boulangeries-patisseries'],
    'boucherie' => ['47.22Z', 'boucheries-charcuteries'],
    'restauration traditionnelle' => ['56.10A', 'restaurants'],
    'restauration rapide' => ['56.10C', 'restaurants'],
    'débits de boissons' => ['56.30Z', 'cafes-bars'],
    'hôtels' => ['55.10Z', 'hotels-hebergement'],
    'taxis' => ['49.32Z', 'taxis-vtc'],
    'VTC (autres transports routiers de voyageurs)' => ['49.39B', 'taxis-vtc'],
    'transport routier de fret' => ['49.41A', 'transport-routier'],
    'déménagement' => ['49.42Z', 'demenageurs'],
    'maçonnerie générale' => ['43.99C', 'maconnerie-gros-oeuvre'],
    'installation électrique' => ['43.21A', 'electriciens'],
    'plomberie' => ['43.22A', 'plombiers-chauffagistes'],
    'chauffage, climatisation' => ['43.22B', 'plombiers-chauffagistes'],
    'menuiserie bois et PVC' => ['43.32A', 'menuisiers'],
    'peinture' => ['43.34Z', 'peintres'],
    'couverture' => ['43.91B', 'couvreurs-charpentiers'],
    'paysagistes' => ['81.30Z', 'paysagistes'],
    'sécurité privée' => ['80.10Z', 'securite-privee'],
    'nettoyage courant' => ['81.21Z', 'nettoyage'],
    'travail temporaire' => ['78.20Z', 'interim-recrutement'],
    'programmation informatique' => ['62.01Z', 'services-informatiques'],
    'édition de logiciels applicatifs' => ['58.29C', 'editeurs-logiciels'],
    'conseil en communication' => ['70.21Z', 'agences-communication'],
    'agences de publicité' => ['73.11Z', 'agences-publicite'],
    'formation continue' => ['85.59A', 'organismes-formation'],
    'auto-écoles' => ['85.53Z', 'auto-ecoles'],
    'pompes funèbres' => ['96.03Z', 'pompes-funebres'],
    'fleuristes' => ['47.76Z', 'fleuristes'],
    'opticiens' => ['47.78A', 'opticiens'],
    'supérettes' => ['47.11C', 'commerces-alimentaires'],
    'holdings' => ['64.20Z', 'holdings-sieges'],
    'salons et congrès' => ['82.30Z', 'evenementiel-salons'],
]);

test('aucune invention : une sous-classe hors table n a PAS de métier, même si son groupe en a', function (string $naf) {
    expect(Metiers::pourNafRev2($naf))->toBeNull();
})->with([
    'activités spécialisées diverses' => ['74.90B'],   // même division que 74.90A (bureaux d'études)
    'autres activités de soutien aux entreprises' => ['82.99Z'],   // même groupe que 82.92Z… et division que 82.30Z
    'autres services personnels' => ['96.09Z'],   // même groupe que 96.02A (coiffure)
    'autres organisations par adhésion' => ['94.99Z'],
    'action sociale sans hébergement n.c.a.' => ['88.99B'],
    'commerce de gros de boissons' => ['46.34Z'],   // division 46, dont 46.1x a un métier
]);

test('seul un code rév. 2 bien formé a un métier ; la casse et les espaces sont tolérés', function () {
    expect(Metiers::pourNafRev2(' 69.20z '))->toBe('experts-comptables')
        ->and(Metiers::pourNafRev2(null))->toBeNull()
        ->and(Metiers::pourNafRev2(''))->toBeNull()
        ->and(Metiers::pourNafRev2('6920Z'))->toBeNull()
        // Un code de 1993 n'est PAS lu comme de la rév. 2 : c'est `naf_rev2`
        // (converti par la table INSEE) qui porte le métier.
        ->and(Metiers::pourNafRev2('74.1C'))->toBeNull()
        ->and(NomenclatureNaf::classer('74.1C')->codeRev2)->toBe('69.20Z')
        ->and(Metiers::pourNafRev2(NomenclatureNaf::classer('74.1C')->codeRev2))->toBe('experts-comptables');
});

test('le slug et le nom de l étiquette de métier', function () {
    expect(EtiquettesClassement::slugMetier('plombiers-chauffagistes'))->toBe('metier-plombiers-chauffagistes')
        ->and(EtiquettesClassement::desirees(null, null, null, '43.22A'))->toBe([
            'metier-plombiers-chauffagistes' => [
                'name' => 'Métier : Plombiers, chauffagistes et climatisation',
                'category' => 'sector',
            ],
        ]);
});
