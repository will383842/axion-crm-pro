<?php

namespace App\Envoi\Chauffe;

/** Un frein qui se déclenche : portée globale (fournisseur null) ou limitée à un fournisseur. */
final class DecisionFrein
{
    public const REBONDS = 'rebonds_durs_trop_nombreux';

    public const PLAINTES = 'plaintes_trop_nombreuses';

    public const INDESIRABLE_TEMOIN = 'indesirable_chez_un_temoin';

    public const REPONSES_EN_BAISSE = 'taux_de_reponse_en_baisse';

    public const REPONSES_NON_TRAITEES = 'reponses_non_traitees';

    public const HOMME_MORT = 'tableau_de_bord_non_valide';

    public function __construct(
        public readonly string $motif,
        public readonly ?string $fournisseur = null,
    ) {}
}
