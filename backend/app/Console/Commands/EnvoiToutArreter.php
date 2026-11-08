<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\ToutArreter;
use Illuminate\Console\Command;

/** « Tout arrêter » : arme le drapeau en base (journalisé), serveur en pause, alerte interne. */
class EnvoiToutArreter extends Command
{
    protected $signature = 'envoi:tout-arreter {workspace : identifiant du workspace} {--auteur= : qui demande}';

    protected $description = 'Arme « Tout arrêter » pour un workspace : plus aucune injection (journalisé)';

    public function handle(ToutArreter $arret): int
    {
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        $arret->armer((string) $this->argument('workspace'), $auteur);
        $this->warn('Tout arrêter ARMÉ pour ce workspace (journalisé).');

        return self::SUCCESS;
    }
}
