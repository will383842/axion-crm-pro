<?php

namespace App\Envoi\Configuration;

enum StatutVersion: string
{
    case Appliquee = 'appliquee';
    case Invalide = 'invalide';
    case RetourArriere = 'retour_arriere';
    case DeriveRefusee = 'derive_refusee';
}
