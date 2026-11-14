<?php

namespace App\Envoi\Configuration;

enum StatutVersion: string
{
    case Appliquee = 'appliquee';
    case Invalide = 'invalide';
    case RetourArriere = 'retour_arriere';
    case DeriveRefusee = 'derive_refusee';
    /** Réponse du serveur inconnue (transport, délai) : l'état du serveur est à vérifier à la main. */
    case Incertaine = 'incertaine';
    /** R15 : version préparée et validée, JAMAIS appliquée (l'application reste un geste humain, fermé au palier 0). */
    case Preparee = 'prete';
}
