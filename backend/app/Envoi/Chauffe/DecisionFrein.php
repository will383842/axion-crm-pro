<?php

namespace App\Envoi\Chauffe;

/** Un frein qui se déclenche : portée globale (fournisseur null) ou limitée à un fournisseur. */
final class DecisionFrein
{
    public const REBONDS = 'rebonds_durs_trop_nombreux';

    public const PLAINTES_ARRET = 'deux_plaintes_en_sept_jours';

    /** Pas une pause : palier tenu + alerte de relecture. */
    public const PLAINTE_RELECTURE = 'plainte_a_relire';

    public const INDESIRABLE_TEMOIN = 'indesirable_chez_un_temoin';

    public const REPONSES_EN_BAISSE = 'taux_de_reponse_en_baisse';

    public const REPONSES_NON_TRAITEES = 'reponses_non_traitees';

    /** 26 § 4.3 : plus de 20 % de reports sur 1 h => pause IP × CE fournisseur seulement. */
    public const REPORTS_EN_HAUSSE = 'reports_en_hausse_sur_une_heure';

    public const HOMME_MORT = 'tableau_de_bord_non_valide';

    /** INF-025 : plus aucun appel à sante() depuis 15 minutes en fenêtre d'envoi. */
    public const SANTE_SILENCIEUSE = 'sante_silencieuse';

    /** R17 : un constat de réputation « mauvaise » (Postmaster, SNDS, JMRP, Orange) => pause vers CE fournisseur seulement. */
    public const REPUTATION_MAUVAISE = 'reputation_mauvaise';

    public function __construct(
        public readonly string $motif,
        public readonly ?string $fournisseur = null,
        /** Durée de la pause en heures ; null = jusqu'à levée humaine. */
        public readonly ?int $dureeHeures = null,
    ) {}
}
