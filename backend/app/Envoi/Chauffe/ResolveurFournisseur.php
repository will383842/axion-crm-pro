<?php

namespace App\Envoi\Chauffe;

/** Fournisseur (gmail / microsoft / autre) du destinataire d'une adresse, d'après les MX de son domaine. */
interface ResolveurFournisseur
{
    /** @return string gmail, microsoft ou autre (constantes de Fournisseur) */
    public function fournisseurDe(string $adresse): string;
}
