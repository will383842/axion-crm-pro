<?php

/**
 * Détection du groupement d'une pharmacie par mots-clés d'enseigne ou de site.
 * Données FICTIVES (dépôt public).
 */

use App\Crm\Pharmacies\Groupement;

it('reconnaît le groupement dans l enseigne', function (string $enseigne, string $attendu) {
    expect(Groupement::detecter($enseigne, null))->toBe($attendu);
})->with([
    ['Pharmacie Lafayette ZZ Test', 'Pharmacie Lafayette'],
    ['GIPHAR ZZ', 'Giphar'],
    ['Pharmacie Alliance ZZ', 'Pharmacie Alliance'],
    ['Leadersanté ZZ', 'Leadersanté'],
    ['PLUS PHARMACIE ZZ', 'Plus Pharmacie'],
]);

it('reconnaît le groupement dans le site web', function () {
    expect(Groupement::detecter(null, 'https://www.pluspharmacie-zz.example/accueil'))->toBe('Plus Pharmacie')
        ->and(Groupement::detecter('', 'zz-giphar.example'))->toBe('Giphar');
});

it('ne devine rien sans mot-clé', function () {
    expect(Groupement::detecter('Pharmacie ZZ du Centre', 'https://www.zz-centre.example'))->toBeNull()
        ->and(Groupement::detecter(null, null))->toBeNull();
});
