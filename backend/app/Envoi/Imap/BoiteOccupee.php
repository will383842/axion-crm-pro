<?php

namespace App\Envoi\Imap;

use RuntimeException;

/** Une autre relève (planifiée ou manuelle) tient déjà cette boîte : rien n'a été lu ni écrit. Message fixe, sans secret. */
final class BoiteOccupee extends RuntimeException
{
    public const MESSAGE = 'Boîte déjà en cours de relève.';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
