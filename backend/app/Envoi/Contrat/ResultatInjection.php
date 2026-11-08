<?php

namespace App\Envoi\Contrat;

use DateTimeImmutable;

final class ResultatInjection
{
    private function __construct(
        public readonly bool $accepte,
        public readonly ?string $idInjection,
        public readonly ?DateTimeImmutable $reporteAu,
    ) {}

    public static function accepte(string $idInjection): self
    {
        return new self(true, $idInjection, null);
    }

    public static function reporte(DateTimeImmutable $jour): self
    {
        return new self(false, null, $jour);
    }
}
