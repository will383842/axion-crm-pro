<?php

namespace App\Console\Commands;

use App\Envoi\Serveur\RejoueurCommandes;
use Illuminate\Console\Command;

/**
 * K1 — rejeu des commandes du journal d'envoi restées SANS acquittement (panne réseau, arrêt du processus).
 * Idempotent par commande_id. Sans effet (code 0) tant que ENVOI_REEL_ACTIF et ENVOI_K1_ACTIF ne sont pas vrais.
 */
class EnvoiRejouerCommandes extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:rejouer-commandes';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Rejoue les commandes du serveur d\'envoi non acquittées (même commande_id, idempotent)';

    public function handle(RejoueurCommandes $rejoueur): int
    {
        $bilan = $rejoueur->rejouer();
        $this->info("commandes rejouées : {$bilan['rejouees']} ; échecs (reprises au prochain passage) : {$bilan['echecs']}");

        return self::SUCCESS;
    }
}
