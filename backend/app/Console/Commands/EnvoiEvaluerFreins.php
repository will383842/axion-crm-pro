<?php

namespace App\Console\Commands;

use App\Envoi\Chauffe\GardeChauffe;
use Illuminate\Console\Command;

/** Évalue les freins automatiques d’un espace et ouvre les pauses (alertes internes). */
class EnvoiEvaluerFreins extends Command
{
    protected $signature = 'envoi:evaluer-freins {workspace : identifiant du workspace}';

    protected $description = 'Évalue les freins d’envoi (rebonds, plaintes, réponses, humain, homme mort)';

    public function handle(GardeChauffe $garde): int
    {
        $ouvertes = $garde->evaluer((string) $this->argument('workspace'), new \DateTimeImmutable);
        foreach ($ouvertes as $d) {
            $this->warn('Pause ouverte : ' . $d->motif . ($d->fournisseur !== null ? ' (' . $d->fournisseur . ')' : ''));
        }
        $this->info(count($ouvertes) . ' pause(s) ouverte(s).');

        return self::SUCCESS;
    }
}
