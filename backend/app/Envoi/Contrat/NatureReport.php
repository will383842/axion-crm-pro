<?php

namespace App\Envoi\Contrat;

/**
 * Nature explicite d'un report (jamais déduite d'un texte).
 *   - Attente : la situation se lève d'elle-même (plafond du jour, quota de chauffe, pause, frein, mode témoins, envoi
 *     réel éteint, arrêt général, boîte occupée) : l'inscription reste active, sans compteur ni limite.
 *   - Echec : refus temporaire du serveur ou erreur certaine avant envoi : compté et plafonné par le séquenceur.
 */
enum NatureReport: string
{
    case Attente = 'attente';
    case Echec = 'echec';
}
