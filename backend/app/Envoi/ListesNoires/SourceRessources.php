<?php

namespace App\Envoi\ListesNoires;

interface SourceRessources
{
    /** @return list<Ressource> */
    public function toutes(): array;

    /**
     * Entrées de configuration inutilisables (rejetées au chargement) : un contrôle qui ne
     * couvre pas ce qu'il devait couvrir n'est pas « vert ».
     *
     * @return list<string>
     */
    public function rejets(): array;
}
