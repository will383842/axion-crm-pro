<?php

namespace App\Console\Commands;

use App\Envoi\Desinscription\RelanceEffetsOpposition;
use Illuminate\Console\Command;

/**
 * Relance les effets (retrait de la file, arrêt par entreprise) des oppositions
 * dont `effets_termines_le` est NULL — sans qu'il faille un nouveau clic.
 * Idempotente, ne supprime rien. La logique (délai croissant, alerte après 10 échecs,
 * journal « espace supprimé » une fois par jour) et son état, en base, sont dans
 * `App\Envoi\Desinscription\RelanceEffetsOpposition`.
 */
class EnvoiRelancerEffetsOpposition extends Command
{
    public const SIGNATURE = 'envoi:relancer-effets-opposition';

    protected $signature = self::SIGNATURE;

    protected $description = 'Relance les effets des oppositions de désinscription non terminés (rien n\'est supprimé)';

    public function handle(RelanceEffetsOpposition $relance): int
    {
        $this->info("Effets d'opposition terminés : {$relance->executer()}.");

        return self::SUCCESS;
    }
}
