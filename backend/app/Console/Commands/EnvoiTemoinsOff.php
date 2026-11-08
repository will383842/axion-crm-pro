<?php

namespace App\Console\Commands;

use App\Envoi\Depot\DepotSocle;
use Illuminate\Console\Command;

/** SEUL moyen d'éteindre le mode témoins : commande locale, journalisée. */
class EnvoiTemoinsOff extends Command
{
    protected $signature = 'envoi:temoins-off {workspace : identifiant du workspace} {--auteur= : qui demande}';

    protected $description = 'Éteint le mode témoins d\'un workspace (local, journalisé, jamais par API)';

    public function handle(DepotSocle $depot): int
    {
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        $depot->eteindreTemoins((string) $this->argument('workspace'), $auteur);
        $this->warn('Mode témoins ÉTEINT pour ce workspace (journalisé).');

        return self::SUCCESS;
    }
}
