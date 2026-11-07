<?php

namespace App\Envoi\Contrat;

final class LotEvenements
{
    /** @param list<EvenementEnvoi> $evenements */
    public function __construct(
        public readonly array $evenements,
        public readonly ?string $curseurSuivant,
    ) {}
}
