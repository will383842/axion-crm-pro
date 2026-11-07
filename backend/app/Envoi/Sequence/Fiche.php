<?php

namespace App\Envoi\Sequence;

use DateTimeImmutable;

/** Ce que la base dit d'une fiche au moment d'envoyer (lu en base, jamais fourni par l'appelant). */
final class Fiche
{
    /** @param  list<array<string, mixed>>  $occurrences */
    public function __construct(
        public readonly int $contactId,
        public readonly int $entrepriseId,
        public readonly string $email,
        public readonly ?string $pays,
        public readonly ?string $siren,
        public readonly ?string $segment,
        public readonly ?DateTimeImmutable $collecteeLe,
        public readonly ?DateTimeImmutable $premiereInfoLe,
        public readonly array $occurrences,
    ) {}
}
