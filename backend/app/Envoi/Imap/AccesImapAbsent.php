<?php

namespace App\Envoi\Imap;

use RuntimeException;

/** Aucun accès IMAP actif n'est posé pour la boîte (ou il appartient à un autre espace). Message fixe : aucun hôte, aucun secret. */
final class AccesImapAbsent extends RuntimeException
{
    public const MESSAGE = 'aucun accès IMAP posé pour une boîte';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
