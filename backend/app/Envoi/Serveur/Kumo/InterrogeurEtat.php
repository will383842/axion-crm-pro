<?php

namespace App\Envoi\Serveur\Kumo;

use App\Envoi\Contrat\EtatMessage;

/** Interroge le serveur sur le sort d'une injection (lecture seule, jamais d'envoi). */
interface InterrogeurEtat
{
    public function etat(string $idInjection): EtatMessage;
}
