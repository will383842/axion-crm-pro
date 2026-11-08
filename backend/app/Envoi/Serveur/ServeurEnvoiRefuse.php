<?php

namespace App\Envoi\Serveur;

use RuntimeException;

/** Refus local ou réponse inacceptable du serveur d'envoi. Le message ne contient JAMAIS d'extrait de réponse. */
final class ServeurEnvoiRefuse extends RuntimeException
{
    public static function motif(string $motif): self
    {
        return new self('serveur d’envoi : ' . $motif);
    }
}
