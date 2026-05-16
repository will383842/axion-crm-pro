<?php

namespace App\Contracts;

use App\Data\Sources\AnnuaireEntrepriseData;

interface AnnuaireEntreprisesClient
{
    public function fetchBySiren(string $siren): ?AnnuaireEntrepriseData;
}
