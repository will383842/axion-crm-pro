<?php

namespace App\Envoi\Chauffe;

/** Fournisseur (gmail / microsoft / autre) du destinataire d'une adresse, d'après les MX de son domaine. */
interface ResolveurFournisseur
{
    /**
     * @param  ?string  $workspaceId  espace demandeur : le cache MX est cloisonné par espace
     * @return string l'une des valeurs de Fournisseur::TOUS, ou Fournisseur::INCONNU si la résolution a échoué */
    public function fournisseurDe(string $adresse, ?string $workspaceId = null): string;
}
