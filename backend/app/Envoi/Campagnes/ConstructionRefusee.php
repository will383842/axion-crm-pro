<?php

namespace App\Envoi\Campagnes;

/**
 * Construction de la séquence d'une campagne refusée (lot X1-L3). `motif` est un code stable ; `reessayable` dit si le même appel peut
 * réussir plus tard sans rien changer. Jamais de QueryException brute : `erreur_base` garde l'exception d'origine en `getPrevious()`.
 *
 * Motifs : jours_non_applicables, objet_b_non_applicable, etapes_invalides, sequence_incoherente (bloquants),
 * introuvable, statut_non_programmee, validation_introuvable (non réessayables), fenetre_annulation_ouverte, concurrence (réessayables), erreur_base.
 */
final class ConstructionRefusee extends \RuntimeException
{
    /** Motifs autorisés en matérialisation (le refus devient définitif pour la campagne). */
    public const BLOQUANTS = ['jours_non_applicables', 'objet_b_non_applicable', 'etapes_invalides', 'sequence_incoherente'];

    public function __construct(public readonly string $motif, public readonly bool $reessayable, string $message, ?\Throwable $prev = null)
    {
        parent::__construct($message, 0, $prev);
    }

    /** Le motif à écrire en matérialisation, ou null si le refus n'est pas bloquant. */
    public function motifMaterialisation(): ?string
    {
        return in_array($this->motif, self::BLOQUANTS, true) ? $this->motif : null;
    }
}
