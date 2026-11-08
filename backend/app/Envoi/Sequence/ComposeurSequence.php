<?php

namespace App\Envoi\Sequence;

/**
 * Compose le message complet (en-têtes In-Reply-To / References compris) à
 * partir d'une demande. `App\Envoi\Message\ComposeurMessage` (lot L2) est sur
 * `main` mais PAS encore branché derrière cette interface. DETTE NOMMÉE (lot
 * suivant L1↔L2↔L6) : `ComposeurMessage` crée son propre Message-ID
 * (`DemandeMessage` n'en accepte pas) ; `DemandeComposition` ne porte ni jeton,
 * ni URL/mailto de désinscription, ni entité, ni adresse postale, ni texte
 * article 14 ; le séquenceur n'écrit pas dans `envoi_messages_envoyes` (L6) et
 * n'appelle pas `Inscriptions::evenement()` depuis le relevé des réponses.
 * Aucun suivi d'ouverture : ni pixel, ni lien traqué.
 */
interface ComposeurSequence
{
    public function composer(DemandeComposition $demande): string;
}
