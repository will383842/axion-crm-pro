<?php

namespace App\Envoi\Sante;

interface EmetteurAlerteSante
{
    /** Vrai si l'alerte est partie par au moins un canal. Ne lève jamais. */
    public function alerter(string $workspaceId, string $source, Constat $constat): bool;
}
