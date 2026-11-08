<?php

namespace App\Envoi\Imap;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Le message envoyé auquel un retour se rattache. `idInjection` est l'UUID de `envoi_injections_smtp`. */
final class MessageOrigine
{
    public function __construct(
        public readonly string $idInjection,
        public readonly string $adresse,
    ) {
        if (! Str::isUuid($idInjection)) {
            throw new InvalidArgumentException('id_injection : un UUID de envoi_injections_smtp est exigé.');
        }
    }
}
