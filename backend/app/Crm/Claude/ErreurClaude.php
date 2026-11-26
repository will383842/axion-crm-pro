<?php

namespace App\Crm\Claude;

use RuntimeException;

/** Erreur typée du client : `type` dit quoi faire (pause, arrêt code 6, etc.). Jamais de clé dans le message. */
class ErreurClaude extends RuntimeException
{
    public const AUCUNE_CLE = 'aucune_cle';

    public const BUDGET = 'budget';

    public const TOUTES_EPUISEES = 'toutes_epuisees';

    public const PAUSE = 'pause';

    public const RECHERCHE = 'erreur_recherche';

    public const SORTIE_INVALIDE = 'sortie_invalide';

    public const RESEAU = 'reseau';

    public const API = 'api';

    public const PAYANT_INACTIF = 'payant_inactif';

    /** Code de sortie de la commande quand plus aucune clé n'est utilisable. */
    public const CODE_SORTIE_TOUTES_EPUISEES = 6;

    public function __construct(public readonly string $type, string $message, int $statut = 0, public readonly ?string $alias = null)
    {
        parent::__construct($message, $statut);
    }
}
