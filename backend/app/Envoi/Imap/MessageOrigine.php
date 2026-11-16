<?php

namespace App\Envoi\Imap;

use Illuminate\Support\Str;
use InvalidArgumentException;

/** Le message envoyé auquel un retour se rattache. `idInjection` est l'UUID du registre (`envoi_injections_smtp` ou `envoi_injections_locales`). */
final class MessageOrigine
{
    public function __construct(
        public readonly string $idInjection,
        public readonly string $adresse,
    ) {
        if (! Str::isUuid($idInjection)) {
            throw new InvalidArgumentException('id_injection : un UUID du registre (envoi_injections_smtp ou envoi_injections_locales) est exigé.');
        }
    }
}
