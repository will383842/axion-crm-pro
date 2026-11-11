<?php

namespace App\Envoi\Chauffe;

/**
 * Budget du jour (ADR E2) : le PLUS PETIT des termes que le serveur calcule, avec le goulot nommé.
 * Un terme non calculé encore (capacité de réponse) vaut null : jamais inventé, jamais compté dans le minimum.
 */
final class BudgetJour
{
    public function __construct(
        public readonly int $budget,
        public readonly string $goulot,
        public readonly int $courbeIp,
        public readonly int $courbeDomaine,
        public readonly int $plafondBoites,
        public readonly int $plafondServeur,
        public readonly ?int $capaciteReponse,
    ) {}
}
