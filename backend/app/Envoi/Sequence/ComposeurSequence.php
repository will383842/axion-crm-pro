<?php

namespace App\Envoi\Sequence;

/**
 * Compose le message complet (en-têtes In-Reply-To / References compris) à
 * partir d'une demande. `App\Envoi\Message\ComposeurMessage` (lot L2) sera
 * branché derrière cette interface quand la branche L2 sera fusionnée.
 * Aucun suivi d'ouverture : ni pixel, ni lien traqué.
 */
interface ComposeurSequence
{
    public function composer(DemandeComposition $demande): string;
}
