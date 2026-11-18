<?php

namespace App\Envoi\Retours;

use App\Envoi\Contrat\EvenementEnvoi;

/**
 * Applique un événement de retour (rebond, plainte, opposition) aux freins.
 * Liaison actuelle : `CompteurRetours` (compteurs de chauffe), appelée par `RecepteurRetoursFreins`
 * dans la chaîne de la relève IMAP.
 */
interface AppliquerRetour
{
    public function appliquer(string $workspaceId, EvenementEnvoi $evenement): void;
}
