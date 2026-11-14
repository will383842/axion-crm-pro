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
        /** Nature du report (sans objet pour un résultat accepté ou incertain). */
        public readonly NatureReport $nature = NatureReport::Attente,
    ) {}

    /** L'identifiant peut manquer pour une ligne ancienne acceptée sans identifiant conservé. */
    public static function accepte(?string $idInjection): self
    {
        return new self(true, $idInjection, null);
    }

    /** Report NET d'ATTENTE : rien n'est parti, la place est rendue, la situation se lève d'elle-même (jamais plafonné). */
    public static function reporte(DateTimeImmutable $jour): self
    {
        return new self(false, null, $jour);
    }

    /** Report NET d'ÉCHEC (refus temporaire du serveur, erreur certaine avant envoi) : compté et plafonné par le séquenceur. */
    public static function reporteEchec(DateTimeImmutable $jour): self
    {
        return new self(false, null, $jour, false, NatureReport::Echec);
    }

    /** Report à issue INCERTAINE : le message est peut-être parti ; la place reste comptée, aucun renvoi automatique. */
    public static function incertain(DateTimeImmutable $jour): self
    {
        return new self(false, null, $jour, true);
    }
}
