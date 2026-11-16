<?php

declare(strict_types=1);

namespace Passerelle\Injection;

/** Refus explicite du moteur d'envoi, avec son code SMTP (4xx : temporaire, 5xx : définitif). Jamais d'adresse ni de message. */
final class RefusKumo extends \RuntimeException
{
    public function __construct(public readonly int $codeSmtp)
    {
        if ($codeSmtp < 400 || $codeSmtp > 599) {
            throw new \InvalidArgumentException('code SMTP de refus attendu entre 400 et 599');
        }
        parent::__construct('refus_kumo');
    }

    public function temporaire(): bool
    {
        return $this->codeSmtp < 500;
    }
}
