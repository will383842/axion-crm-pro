<?php

namespace App\Services\AnnuaireEntreprises\Mocks;

use App\Contracts\AnnuaireEntreprisesClient;
use App\Data\Sources\AnnuaireEntrepriseData;

class MockAnnuaireEntreprisesClient implements AnnuaireEntreprisesClient
{
    public function fetchBySiren(string $siren): ?AnnuaireEntrepriseData
    {
        return new AnnuaireEntrepriseData(
            siren: $siren,
            denomination: 'Mock Annuaire ' . $siren,
            representatives: [
                [
                    'role'       => 'gerant',
                    'first_name' => 'Mock',
                    'last_name'  => 'Dirigeant',
                    'birth_date' => null,
                ],
            ],
        );
    }
}
