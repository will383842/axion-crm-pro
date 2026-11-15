<?php

namespace App\Envoi\Exceptions;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Refus DÉFINITIF et certain du serveur d'envoi (5xx sur le message) : rien n'est parti, la place est rendue. */
final class RefusDefinitifServeur extends RuntimeException
{
    public function __construct(string $message = '', public readonly ?int $codeSmtp = null, ?Throwable $previous = null)
    {
        if ($codeSmtp !== null && ($codeSmtp < 200 || $codeSmtp > 599)) {
            throw new InvalidArgumentException('Code SMTP hors de 200 à 599.');
        }
        parent::__construct($message, 0, $previous);
    }
}
