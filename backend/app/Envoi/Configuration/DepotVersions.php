<?php

namespace App\Envoi\Configuration;

interface DepotVersions
{
    public function noter(string $workspaceId, string $empreinte, StatutVersion $statut, ?string $motif = null): void;
}
