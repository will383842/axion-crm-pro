<?php

namespace App\Envoi\ListesNoires;

interface SourceRessources
{
    /** @return list<Ressource> */
    public function toutes(): array;
}
