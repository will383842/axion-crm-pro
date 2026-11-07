<?php

namespace App\Envoi\Sequence;

/** Événements qui ARRÊTENT une inscription. `reponse` et `opposition` arrêtent aussi toute l'entreprise. */
final class Evenement
{
    public const REPONSE = 'reponse';

    public const STOP = 'stop';

    public const OPPOSITION = 'opposition';

    public const REBOND_DUR = 'rebond_dur';

    public const TOUS = [self::REPONSE, self::STOP, self::OPPOSITION, self::REBOND_DUR];

    /** Motifs qui arrêtent les AUTRES personnes de la même entreprise. */
    public const PROPAGENT_A_L_ENTREPRISE = [self::REPONSE, self::OPPOSITION];

    public const MOTIF_ENTREPRISE = 'entreprise_arretee';
}
