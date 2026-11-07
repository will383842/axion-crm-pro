<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\DepotChauffe;
use App\Envoi\Depot\DepotSocle;
use Illuminate\Console\Command;

/** Lever une pause est une décision HUMAINE (la ligne reste, datée). */
class EnvoiLeverPause extends Command
{
    protected $signature = 'envoi:lever-pause {workspace} {pause : identifiant de la pause} {--auteur= : qui lève}';

    protected $description = 'Lève une pause d’envoi (journalisé, la ligne est conservée)';

    public function handle(DepotChauffe $depot, DepotSocle $socle): int
    {
        $ws = (string) $this->argument('workspace');
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        if (! $depot->leverPause($ws, (int) $this->argument('pause'), $auteur)) {
            $this->error('Aucune pause active avec cet identifiant.');

            return self::FAILURE;
        }
        $socle->journaliser($ws, 'pause_levee', $auteur, ['pause' => (int) $this->argument('pause')]);
        $this->info('Pause levée (journalisé).');

        return self::SUCCESS;
    }
}
