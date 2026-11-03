<?php

namespace App\Console\Concerns;

/**
 * L3c-0 (ADR contrat v3 § 6, « AUCUNE PURGE ») : les commandes qui supprimaient
 * des personnes, des contacts ou des candidats refusent SANS CONDITION.
 *
 * Ni `--force`, ni `CRM_PURGE_ENABLED`, ni `--dry-run` n'y changent rien : la
 * commande ne lit aucune donnée et n'en écrit aucune. Une opposition est une
 * mise à l'écart (`opposition_at`), jamais une suppression.
 */
trait RefuseToutePurgeDePersonnes
{
    protected function refuserSansCondition(): int
    {
        $this->error(
            'Commande neutralisée (L3c-0, ADR contrat v3 § 6) : aucune suppression de personne, '
            . 'de contact ni de candidat n\'est permise, même avec --force. '
            . 'Une opposition se traite par mise à l\'écart ; une demande d\'effacement est signalée au propriétaire.',
        );

        return self::FAILURE;
    }
}
