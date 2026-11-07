<?php

namespace App\Envoi\Chauffe;

/** Fournisseur (gmail / microsoft / autre) du destinataire d'une adresse, d'après les MX de son domaine. */
interface ResolveurFournisseur
{
    /** @return string l'une des valeurs de Fournisseur::TOUS */
    public function fournisseurDe(string $adresse): string;
}
