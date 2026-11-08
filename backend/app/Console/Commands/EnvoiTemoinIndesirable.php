<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\GardeChauffe;
use Illuminate\Console\Command;

/** Un message de test est arrivé en indésirables chez un témoin : pause vers CE fournisseur seulement. */
class EnvoiTemoinIndesirable extends Command
{
    protected $signature = 'envoi:temoin-indesirable {workspace : identifiant du workspace} {adresse : adresse du témoin concerné} {--auteur= : qui signale}';

    protected $description = 'Signale un message en indésirables chez un témoin : pause vers le fournisseur du témoin (journalisé)';

    public function handle(GardeChauffe $garde): int
    {
        $ouverte = $garde->indesirableChezTemoin((string) $this->argument('workspace'), (string) $this->argument('adresse'));
        $this->warn($ouverte ? 'Pause ouverte vers le fournisseur du témoin (journalisé).' : 'Une pause identique est déjà active.');

        return self::SUCCESS;
    }
}
