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
}
