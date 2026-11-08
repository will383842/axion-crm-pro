<?php

namespace App\Envoi\Sequence;

/** Événements qui ARRÊTENT une inscription. `reponse` et `opposition` arrêtent aussi toute l'entreprise ; `stop`, `opposition` et `rebond_dur` valent pour l'adresse. */
final class Evenement
{
    public const REPONSE = 'reponse';

    public const STOP = 'stop';

    public const OPPOSITION = 'opposition';

    public const REBOND_DUR = 'rebond_dur';

    public const TOUS = [self::REPONSE, self::STOP, self::OPPOSITION, self::REBOND_DUR];

    /** Motifs qui arrêtent les AUTRES personnes de la même entreprise. */
    public const PROPAGENT_A_L_ENTREPRISE = [self::REPONSE, self::OPPOSITION];

    /**
     * Événements qui valent pour l'ADRESSE (donc pour TOUTES ses inscriptions, présentes et à venir) :
     * un STOP, une opposition ou un rebond dur ne sont pas propres à une séquence.
     */
    public const VALENT_POUR_L_ADRESSE = [self::STOP, self::OPPOSITION, self::REBOND_DUR];

    /** Motif posé par le séquenceur quand la porte refuse pour opposition (liste de suppression). */
    public const MOTIF_GARDE_OPPOSITION = 'garde:opposition';

    public const MOTIF_ENTREPRISE = 'entreprise_arretee';

    /** Motif posé sur les AUTRES inscriptions de la même adresse. */
    public const MOTIF_ADRESSE = 'adresse_arretee';
}
