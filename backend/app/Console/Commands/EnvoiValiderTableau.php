<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use Illuminate\Console\Command;

/** Homme mort : une personne confirme avoir regardé le tableau de bord (journalisé). */
class EnvoiValiderTableau extends Command
{
    protected $signature = 'envoi:valider-tableau {workspace : identifiant du workspace} {--auteur= : qui valide}';

    protected $description = 'Valide le tableau de bord d’envoi (remet à zéro le compteur de l’homme mort)';

    public function handle(DepotSocle $depot): int
    {
        $depot->journaliser((string) $this->argument('workspace'), 'tableau_valide', (string) ($this->option('auteur') ?: get_current_user()));
        $this->info('Tableau de bord validé (journalisé).');

        return self::SUCCESS;
    }
}
