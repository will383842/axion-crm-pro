<?php

/**
 * DOUBLONS DE LA PRESSE — la règle PURE (`DoublonsPresse::juger`, 01/10).
 *
 * Chaque règle face à son témoin : le cas strict de référence est FUSIONNABLE,
 * et changer UNE seule chose le fait basculer. Fixtures FICTIVES.
 */

use App\Crm\Presse\DoublonsPresse;

/** @return array{id: int, siren: ?string, presse: bool, manuelle: bool, departements: list<string>, types: list<string>, emails: list<string>, rang: int, contacts: int} */
function dpjProfil(array $valeurs = []): array
{
    return array_merge([
        'id' => 1, 'siren' => null, 'presse' => true, 'manuelle' => false, 'departements' => [],
        'types' => ['tv'], 'emails' => [], 'rang' => 0, 'contacts' => 0,
    ], $valeurs);
}

test('TÉMOIN — deux fiches sans SIREN, même type, sans département ni adresse : stricte', function () {
    expect(DoublonsPresse::juger(dpjProfil(), dpjProfil(['id' => 2])))->toBe(DoublonsPresse::STRICTE)
        ->and(DoublonsPresse::juger(dpjProfil(['departements' => ['31']]), dpjProfil(['id' => 2])))->toBe(DoublonsPresse::STRICTE)
        ->and(DoublonsPresse::juger(dpjProfil(['departements' => ['31'], 'emails' => ['r@zz.example.invalid']]), dpjProfil(['id' => 2, 'departements' => ['31'], 'emails' => ['r@zz.example.invalid']])))->toBe(DoublonsPresse::STRICTE);
});

test('deux départements différents : deux éditions, jamais un doublon — même avec un SIREN', function () {
    expect(DoublonsPresse::juger(dpjProfil(['departements' => ['31']]), dpjProfil(['id' => 2, 'departements' => ['81']])))->toBe(DoublonsPresse::EDITIONS)
        ->and(DoublonsPresse::juger(dpjProfil(['departements' => ['31'], 'siren' => '940000001']), dpjProfil(['id' => 2, 'departements' => ['81']])))->toBe(DoublonsPresse::EDITIONS);
});

test('une seule différence suffit à passer « à vérifier »', function (array $a, array $b) {
    expect(DoublonsPresse::juger(dpjProfil($a), dpjProfil(['id' => 2] + $b)))->toBe(DoublonsPresse::A_VERIFIER);
})->with([
    'une fiche à SIREN (journal et éditeur)' => [['siren' => '940000001'], []],
    'deux SIREN différents' => [['siren' => '940000001'], ['siren' => '940000002']],
    'relation saisie à la main' => [[], ['manuelle' => true]],
    'fiche hors presse' => [[], ['presse' => false]],
    'émission et chaîne' => [['types' => ['tv']], ['types' => ['tv_emission']]],
    'adresses contradictoires' => [['emails' => ['a@zz.example.invalid']], ['emails' => ['b@zz.example.invalid']]],
    'plusieurs départements sur une fiche' => [['departements' => ['31', '81']], ['departements' => ['81']]],
]);

test('la fiche gardée : la source la plus fiable, puis le plus de personnes, puis le plus petit identifiant', function () {
    expect(DoublonsPresse::meilleure([dpjProfil(['id' => 1, 'rang' => 3]), dpjProfil(['id' => 2, 'rang' => 5])]))->toBe(2)
        ->and(DoublonsPresse::meilleure([dpjProfil(['id' => 1, 'rang' => 5]), dpjProfil(['id' => 2, 'rang' => 5, 'contacts' => 2])]))->toBe(2)
        ->and(DoublonsPresse::meilleure([dpjProfil(['id' => 7, 'rang' => 5]), dpjProfil(['id' => 3, 'rang' => 5])]))->toBe(3);
});

test('chaque type de presse a sa famille ; la production n est jamais groupée', function () {
    expect(DoublonsPresse::typesCouverts())->toBeTrue()
        ->and(DoublonsPresse::FAMILLES['tv_emission'])->toBe(DoublonsPresse::FAMILLES['tv'])
        ->and(DoublonsPresse::FAMILLES['radio'])->not->toBe(DoublonsPresse::FAMILLES['tv'])
        ->and(DoublonsPresse::FAMILLES['presse_quotidien'])->not->toBe(DoublonsPresse::FAMILLES['radio']);
});
