<?php

/**
 * Contrat v3 d'origine des contacts : le dictionnaire fermé « Famille › Détail »
 * est copié TEL QUEL depuis le site (src/content/dictionnaire-origine.v1.json).
 * Ce test échoue si la copie diverge : même algorithme que le site
 * (SHA-256 du JSON canonique, clés triées, sans espace, Unicode non échappé).
 * Changer le dictionnaire = nouvelle version, décidée côté site d'abord.
 */

const EMPREINTE_DICTIONNAIRE_ORIGINE = '10a65d4d683fbd6fd5d65701c964bd468211352af8dcd5da0a1d8817e75f54af';

function contratsCanonique(mixed $v): string
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            return '['.implode(',', array_map('contratsCanonique', $v)).']';
        }
        ksort($v, SORT_STRING);
        $parts = [];
        foreach ($v as $k => $x) {
            $parts[] = json_encode((string) $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).':'.contratsCanonique($x);
        }

        return '{'.implode(',', $parts).'}';
    }

    return json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function contratsLire(string $fichier): array
{
    return json_decode(file_get_contents(base_path('tests/fixtures/contrats/'.$fichier)), true, 512, JSON_THROW_ON_ERROR);
}

it('le dictionnaire d\'origine copié a la même empreinte que celui du site', function () {
    $empreinte = hash('sha256', contratsCanonique(contratsLire('dictionnaire-origine.v1.json')));

    expect($empreinte)->toBe(EMPREINTE_DICTIONNAIRE_ORIGINE);
});

it('porte le libellé « Tour France IA »', function () {
    expect(contratsLire('dictionnaire-origine.v1.json')['familles']['tour_france_ia']['libelle'])
        ->toBe('Tour France IA');
});

it('l\'empreinte change si une clé change (contre-témoin)', function () {
    $d = contratsLire('dictionnaire-origine.v1.json');
    $d['familles']['autre']['details']['ajout_test'] = ['libelle' => 'x'];

    expect(hash('sha256', contratsCanonique($d)))->not->toBe(EMPREINTE_DICTIONNAIRE_ORIGINE);
});

it('la charge CRM v3 est lisible, en version 3, avec des adresses .test', function () {
    $charge = contratsLire('charge-crm-v3.json');

    expect($charge['schema_version'])->toBe(3)
        ->and($charge['person']['email'])->toEndWith('.test');
});
