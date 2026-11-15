<?php

namespace App\Console\Commands;

use App\Envoi\Orchestration\ChefOrchestre;
use Illuminate\Console\Command;

/**
 * Un cycle d'envoi (chef d'orchestre). Porte fermée (ENVOI_REEL_ACTIF=false) : ne fait rien. Deux exécutions simultanées :
 * le verrou du chef d'orchestre n'en laisse travailler qu'une (`withoutOverlapping` ne protège que la tâche planifiée).
 */
class EnvoiCycle extends Command
{
    public const SIGNATURE_PLANIFIEE = 'envoi:cycle';

    protected $signature = self::SIGNATURE_PLANIFIEE;

    protected $description = 'Un cycle d\'envoi : séquenceur espace par espace (rien si la porte d\'envoi réel est fermée)';

    public function handle(ChefOrchestre $chef): int
    {
        $b = $chef->cycle();
        if (($b['porte_ouverte'] ?? false) !== true) {
            $this->info('porte fermée : rien à faire.');

            return self::SUCCESS;
        }
        if (($b['verrou_pris'] ?? false) === true) {
            $this->info('un autre cycle est en cours.');

            return self::SUCCESS;
        }
        $this->info("espaces={$b['espaces']} traités={$b['traites']} sautés={$b['sautes']} erreurs={$b['erreurs']}");

        return ((int) $b['erreurs']) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
