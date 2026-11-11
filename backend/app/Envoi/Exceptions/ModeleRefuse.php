<?php

namespace App\Envoi\Exceptions;

use RuntimeException;

/** Écriture de modèle refusée (modèle introuvable dans l'espace, modèle archivé). */
final class ModeleRefuse extends RuntimeException
{
    public static function introuvable(): self
    {
        return new self('Modèle introuvable dans cet espace.', 404);
    }

    public static function archive(): self
    {
        return new self('Modèle archivé : plus de nouvelle version.', 409);
    }
}
