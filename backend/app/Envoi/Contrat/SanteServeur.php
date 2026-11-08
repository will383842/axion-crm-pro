<?php

namespace App\Envoi\Contrat;

final class SanteServeur
{
    public function __construct(
        public readonly bool $ok,
        public readonly string $detail,
    ) {}
}
