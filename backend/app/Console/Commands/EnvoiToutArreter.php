<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use Illuminate\Console\Command;

/** « Tout arrêter » : arme le drapeau en base (journalisé). Le reste du dispositif viendra avec le lot L7. */
class EnvoiToutArreter extends Command
{
    protected $signature = 'envoi:tout-arreter {workspace : identifiant du workspace} {--auteur= : qui demande}';

    protected $description = 'Arme « Tout arrêter » pour un workspace : plus aucune injection (journalisé)';

    public function handle(DepotSocle $depot): int
    {
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        $depot->armerToutArreter((string) $this->argument('workspace'), $auteur);
        $this->warn('Tout arrêter ARMÉ pour ce workspace (journalisé).');

        return self::SUCCESS;
    }
}
