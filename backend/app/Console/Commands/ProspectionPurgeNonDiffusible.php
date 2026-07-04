<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Supprime les entreprises « [ND] » (Non Diffusible) : personnes ayant refusé la
 * diffusion publique de leurs données INSEE (droit RGPD). Nom masqué, inutiles pour
 * la prospection ET non contactables (opt-out). Suppression définitive.
 */
class ProspectionPurgeNonDiffusible extends Command
{
    protected $signature = 'prospection:purge-non-diffusible';

    protected $description = 'Supprime les entreprises « [ND] » (non diffusibles RGPD).';

    public function handle(): int
    {
        $count = DB::table('companies')->where('denomination', '[ND]')->count();
        DB::table('companies')->where('denomination', '[ND]')->delete();
        $this->info("✅ {$count} entreprises « [ND] » (non diffusibles) supprimées.");
        return self::SUCCESS;
    }
}
