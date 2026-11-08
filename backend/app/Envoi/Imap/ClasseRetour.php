<?php

namespace App\Envoi\Imap;

/** Les six classes de retour. Jamais d'« ouverture » : on n'en mesure aucune. */
enum ClasseRetour: string
{
    case ReponseHumaine = 'reponse_humaine';
    case AutoReponse = 'auto_reponse';
    case Desinscription = 'desinscription';
    case Hostile = 'hostile';
    case RebondDur = 'rebond_dur';
    case RebondMou = 'rebond_mou';
}
