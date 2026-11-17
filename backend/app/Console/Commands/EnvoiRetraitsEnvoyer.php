<?php

namespace App\Console\Commands;

use App\Envoi\Desinscription\EnvoiRetraitsDemandes;
use Illuminate\Console\Command;

/**
 * Envoie les demandes de retrait en attente (C3-3). Interrupteurs d'envoi éteints : aucune connexion, les demandes restent en attente.
 */
class EnvoiRetraitsEnvoyer extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:retraits:envoyer';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Envoie au serveur d\'envoi les demandes de retrait nées des oppositions (rien ne part si l\'envoi réel est éteint)';

    public function handle(EnvoiRetraitsDemandes $envoi): int
    {
        $b = $envoi->envoyer();
        $this->info("envoyees={$b['envoyees']} sans_objet={$b['sans_objet']} en_attente={$b['en_attente']} echecs={$b['echecs']} en_retard={$b['en_retard']}");

        return self::SUCCESS;
    }
}
