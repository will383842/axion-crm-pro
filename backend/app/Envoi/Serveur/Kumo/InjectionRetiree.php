<?php

namespace App\Envoi\Serveur\Kumo;

use RuntimeException;

/** La passerelle a refusé l'injection (409 `retire`) : l'adresse est en liste de retrait. Ce n'est pas un refus du message. */
final class InjectionRetiree extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('injection refusée : adresse retirée (opposition)');
    }
}
