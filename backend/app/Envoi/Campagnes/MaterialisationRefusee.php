<?php

namespace App\Envoi\Campagnes;

/**
 * Matérialisation d'une campagne refusée (lot X1-L4-B). `motif` est un code stable ; `reessayable` dit si le même appel peut réussir plus tard.
 * Jamais de QueryException brute : `erreur_base` garde l'exception d'origine en `getPrevious()`.
 *
 * Motifs : reglage_non_applique, statut_non_materialisable, cible_non_supportee, population_non_ouverte, campagne_introuvable (non réessayables),
 * plafond_nul, horizon_plein, concurrence, lecture_impossible, construction_a_reessayer (réessayables), erreur_base.
 * Aucun de ces motifs n'est écrit dans la ligne de matérialisation (la liste fermée de la base ne les connaît pas) : rien n'est écrit.
 */
final class MaterialisationRefusee extends \RuntimeException
{
    public function __construct(public readonly string $motif, public readonly bool $reessayable, string $message, ?\Throwable $prev = null)
    {
        parent::__construct($message, 0, $prev);
    }
}
