<?php

namespace App\Envoi\ListesNoires;

interface Alerteur
{
    public function alerter(Ressource $r, string $zone, string $reponse): void;
}
