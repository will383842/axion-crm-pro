<?php

namespace App\Envoi\Smtp;

use RuntimeException;

/** Refus local de l'adaptateur SMTP : RIEN n'est parti, aucun état d'injection n'a été posé. */
final class EnvoiSmtpRefuse extends RuntimeException
{
    public static function motif(string $motif): self
    {
        return new self('Envoi SMTP refusé : ' . $motif);
    }
}
