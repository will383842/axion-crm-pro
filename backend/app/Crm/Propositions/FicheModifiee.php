<?php

namespace App\Crm\Propositions;

/**
 * La fiche ne porte plus la valeur que le propriétaire a vue à l'écran
 * (relectures #316) : rien n'est écrit, la décision est à reprendre après
 * rechargement — 409 côté API.
 */
final class FicheModifiee extends PropositionImpossible {}
