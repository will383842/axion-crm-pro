<?php

namespace App\Envoi\Sante;

final class Constat
{
    public const ATTENTION = 'attention';

    public const CRITIQUE = 'critique';

    /** Palier : 1 = attention, 2 = attention renforcée (certificat à 14 jours), 3 = critique. Une alerte repart quand il monte. */
    public readonly int $palier;

    public function __construct(
        public readonly string $code,
        public readonly string $gravite,
        public readonly string $detail,
        ?int $palier = null,
    ) {
        $this->palier = $palier ?? ($gravite === self::CRITIQUE ? 3 : 1);
    }
}
