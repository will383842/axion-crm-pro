<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseToutePurgeDePersonnes;
use Illuminate\Console\Command;

/**
 * L3c-0 (ADR contrat v3 § 6 et F1, « AUCUNE PURGE ») — commande NEUTRALISÉE.
 *
 * Elle supprimait des personnes, des contacts ou des candidats (ou des fiches
 * d'entrepreneurs individuels, dont les contacts partaient en cascade). Elle
 * refuse désormais sans condition, même avec `--force` ; son ancien code a été
 * retiré. Les options sont gardées pour ne pas casser un appel existant.
 */
class RgpdPurgeBusinessProspects extends Command
{
    use RefuseToutePurgeDePersonnes;

    protected $signature = 'rgpd:purge-business-prospects {--dry-run : Ignoré (commande neutralisée)} {--force : Ignoré (commande neutralisée)}';

    protected $description = "Neutralisée (L3c-0) : l'ancienne purge des personnes froides est refusée sans condition";

    public function handle(): int
    {
        return $this->refuserSansCondition();
    }
}
