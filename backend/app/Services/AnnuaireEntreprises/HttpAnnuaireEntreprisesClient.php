<?php

namespace App\Services\AnnuaireEntreprises;

use App\Contracts\AnnuaireEntreprisesClient;
use App\Data\Sources\AnnuaireEntrepriseData;

class HttpAnnuaireEntreprisesClient implements AnnuaireEntreprisesClient
{
    public function fetchBySiren(string $siren): ?AnnuaireEntrepriseData
    {
        throw new \LogicException('HttpAnnuaireEntreprisesClient implemented in Sprint 5.');
    }
}
