<?php

namespace App\Envoi\ListesNoires;

interface Alerteur
{
    public function alerter(Ressource $r, string $zone, string $reponse): void;

    /** Contrôle impossible ou non fiable (résolveur public, sonde muette, erreur DNS, ressource invalide…). */
    public function alerterNonFiable(Ressource $r, string $raison): void;
}
