<?php

namespace App\Envoi\Imap;

/** Le message envoyé auquel un retour se rattache. */
final class MessageOrigine
{
    public function __construct(
        public readonly string $idInjection,
        public readonly string $adresse,
    ) {}
}
