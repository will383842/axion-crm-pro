<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\ToutArreter;
use Illuminate\Console\Command;

/** Reprise après « Tout arrêter » : commande LOCALE, journalisée, jamais exposée par l'API. */
class EnvoiReprendre extends Command
{
    protected $signature = 'envoi:reprendre {workspace : identifiant du workspace} {--auteur= : qui reprend}';

    protected $description = 'Lève « Tout arrêter » pour un workspace (local, journalisé, jamais par API)';

    public function handle(ToutArreter $arret): int
    {
        $auteur = (string) ($this->option('auteur') ?: get_current_user());
        if (! $arret->reprendre((string) $this->argument('workspace'), $auteur)) {
            $this->error('« Tout arrêter » n’est pas armé pour ce workspace : rien à reprendre.');

            return self::FAILURE;
        }
        $this->warn('Reprise (journalisée) : « Tout arrêter » levé pour ce workspace.');

        return self::SUCCESS;
    }
}
