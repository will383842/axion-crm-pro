<?php

namespace App\Envoi\Verdicts;

use DateTimeImmutable;
use InvalidArgumentException;

/** Un verdict d'adresse (valeur immuable). On n'écrit jamais « inconnu » : quand on ne sait pas, rien n'est écrit. */
final class VerdictAdresse
{
    public const VALIDE = 'valide';

    public const INVALIDE = 'invalide';

    public const RISQUE = 'risque';

    /** Liste fermée de la migration VERDICTS (CHECK `envoi_verdicts_adresse_motif_ck`), même ordre. */
    public const MOTIFS = ['syntaxe', 'jetable', 'rebond_dur', 'role_technique', 'generique', 'mx', 'a', 'mx_nul', 'sans_courrier', 'inexistant'];

    public function __construct(
        public readonly string $verdict,
        public readonly string $motif,
        public readonly DateTimeImmutable $verifieLe,
    ) {
        if (! in_array($verdict, [self::VALIDE, self::INVALIDE, self::RISQUE], true)) {
            throw new InvalidArgumentException('Verdict d’adresse hors liste.');
        }
        if (! in_array($motif, self::MOTIFS, true)) {
            throw new InvalidArgumentException('Motif de verdict hors liste.');
        }
    }
}
