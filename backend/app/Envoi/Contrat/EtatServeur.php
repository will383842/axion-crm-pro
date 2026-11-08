<?php

namespace App\Envoi\Contrat;

final class EtatServeur
{
    public function __construct(
        public readonly bool $enPause,
        public readonly int $injectes,
    ) {}
}
