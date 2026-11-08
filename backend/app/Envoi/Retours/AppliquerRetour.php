<?php

namespace App\Envoi\Retours;

use App\Envoi\Contrat\EvenementEnvoi;

/** Interface seule : implémentée plus tard par un autre lot. */
interface AppliquerRetour
{
    public function appliquer(string $workspaceId, EvenementEnvoi $evenement): void;
}
