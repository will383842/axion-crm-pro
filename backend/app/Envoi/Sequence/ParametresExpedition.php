<?php

namespace App\Envoi\Sequence;

/** Expéditeur de la séquence : fournis par l'appelant (configuration juridique), jamais inventés ici. */
final class ParametresExpedition
{
    public function __construct(
        public readonly string $domaine,
        public readonly string $expediteur,
        public readonly string $entiteLegale,
    ) {}
}
