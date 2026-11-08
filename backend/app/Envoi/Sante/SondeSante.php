<?php

namespace App\Envoi\Sante;

interface SondeSante
{
    /** @throws \Throwable serveur muet, injoignable ou réponse inacceptable : le silence compte pour l'homme mort */
    public function mesurer(string $workspaceId, string $source): MesuresSante;
}
