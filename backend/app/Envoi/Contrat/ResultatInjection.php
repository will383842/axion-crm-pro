<?php

namespace App\Envoi\Contrat;

use DateTimeImmutable;

final class ResultatInjection
{
    private function __construct(
        public readonly bool $accepte,
        public readonly ?string $idInjection,
        public readonly ?DateTimeImmutable $reporteAu,
        /** Issue INCERTAINE (le message est peut-être parti) : la passerelle garde la place comptée (`a_verifier`). */
        public readonly bool $incertain = false,
    ) {}

    public static function accepte(string $idInjection): self
    {
        return new self(true, $idInjection, null);
    }

    /** Report NET : rien n'est parti, la place est rendue. */
    public static function reporte(DateTimeImmutable $jour): self
    {
        return new self(false, null, $jour);
    }

    /** Report à issue INCERTAINE : le message est peut-être parti ; la place reste comptée, aucun renvoi automatique. */
    public static function incertain(DateTimeImmutable $jour): self
    {
        return new self(false, null, $jour, true);
    }
}
