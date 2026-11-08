<?php

namespace App\Envoi\Serveur\Kumo;

use RuntimeException;

/** Coupure ou délai du transport. `apresData` vrai = le sort du message est INCERTAIN (jamais renvoyé). */
final class CoupureTransport extends RuntimeException
{
    public function __construct(public readonly bool $apresData)
    {
        parent::__construct($apresData ? 'coupure après DATA' : 'coupure avant DATA');
    }
}
