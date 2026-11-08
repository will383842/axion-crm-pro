<?php

namespace App\Console\Commands;

use App\Envoi\Contrat\ServeurEnvoi;
use Illuminate\Console\Command;

/**
 * Battement de l'homme mort (INF-025) : appelle `sante()` du serveur d'envoi. Planifié toutes les 5 minutes ;
 * si le planificateur s'arrête, 15 minutes plus tard toutes les files passent en pause.
 */
class EnvoiSante extends Command
{
    protected $signature = 'envoi:sante';

    protected $description = 'Sonde la santé du serveur d’envoi (battement de l’homme mort, INF-025)';

    public function handle(ServeurEnvoi $serveur): int
    {
        $s = $serveur->sante();
        $this->line(($s->ok ? 'OK' : 'KO') . ' : ' . $s->detail);

        return $s->ok ? self::SUCCESS : self::FAILURE;
    }
}
