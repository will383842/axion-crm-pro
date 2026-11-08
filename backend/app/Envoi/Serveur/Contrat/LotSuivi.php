<?php

namespace App\Envoi\Serveur\Contrat;

final class LotSuivi
{
    /** @param list<EvenementSuivi> $evenements */
    public function __construct(public readonly array $evenements, public readonly ?string $curseurSuivant) {}
}
