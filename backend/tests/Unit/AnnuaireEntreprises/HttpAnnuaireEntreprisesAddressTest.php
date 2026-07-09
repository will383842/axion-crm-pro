<?php

use App\Services\AnnuaireEntreprises\HttpAnnuaireEntreprisesClient;
use Illuminate\Support\Facades\Http;

/*
 * Correctif terrain #6 — backfill adresse siège depuis recherche-entreprises.api.gouv.fr.
 */

it('exposes siège address from geo_adresse', function () {
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [[
                'nom_complet'         => 'Acme SAS',
                'activite_principale' => '6201Z',
                'siege'               => [
                    'geo_adresse'     => '10 Rue de la Paix 75002 Paris',
                    'code_postal'     => '75002',
                    'libelle_commune' => 'Paris',
                ],
            ]],
        ], 200),
    ]);

    $data = (new HttpAnnuaireEntreprisesClient())->fetchBySiren('123456789');

    expect($data)->not->toBeNull();
    expect($data->address)->toBe('10 Rue de la Paix 75002 Paris');
    expect($data->postcode)->toBe('75002');
    expect($data->city)->toBe('Paris');
});

it('reconstructs siège address from voie components when geo_adresse missing', function () {
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [[
                'nom_complet' => 'Foo SARL',
                'siege'       => [
                    'numero_voie'     => '5',
                    'type_voie'       => 'AVENUE',
                    'libelle_voie'    => 'DES CHAMPS',
                    'code_postal'     => '69001',
                    'libelle_commune' => 'Lyon',
                ],
            ]],
        ], 200),
    ]);

    $data = (new HttpAnnuaireEntreprisesClient())->fetchBySiren('987654321');

    expect($data->address)->toBe('5 AVENUE DES CHAMPS');
    expect($data->postcode)->toBe('69001');
    expect($data->city)->toBe('Lyon');
});

it('returns null address when siège absent', function () {
    Http::fake([
        'recherche-entreprises.api.gouv.fr/*' => Http::response([
            'results' => [['nom_complet' => 'No Address Co']],
        ], 200),
    ]);

    $data = (new HttpAnnuaireEntreprisesClient())->fetchBySiren('111222333');

    expect($data->address)->toBeNull();
    expect($data->postcode)->toBeNull();
    expect($data->city)->toBeNull();
});
