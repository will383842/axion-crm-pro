<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Recul de chauffe refusé : le code vaut 409 (conflit d'état) — la route qui l'exposera est la fiche F07b. */
final class ReculRefuse extends RuntimeException
{
    public const STATUT_HTTP = 409;

    public static function dejaAuPlusBas(string $type, string $ressource): self
    {
        return new self("Recul refusé : la ressource {$type} « {$ressource} » est déjà au palier P1.", self::STATUT_HTTP);
    }

    public function statut(): int
    {
        return self::STATUT_HTTP;
    }
}
