<?php

namespace App\Envoi\Gardes;

/** Résultat de la porte : un refus lisible (ou null) et des alertes non bloquantes. */
final class VerdictEnvoi
{
    /** @param  list<string>  $alertes */
    public function __construct(public readonly ?string $refus, public readonly array $alertes = []) {}

    public function autorise(): bool
    {
        return $this->refus === null;
    }
}
