<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Supprime les fiches dont la dénomination est `[ND]` — « non diffusible » au
 * sens de l'INSEE : l'entreprise a demandé que ses données ne soient pas
 * publiées. Suppression DÉFINITIVE.
 *
 * 🔴 CETTE COMMANDE SUPPRIMAIT SANS AUCUNE GARDE (audit 360, B15-004).
 * Sa condition est plus étroite que celle de sa jumelle, mais elle méritait la
 * même protection : un essai à blanc, un plafond, une confirmation.
 */
class ProspectionPurgeNonDiffusible extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'prospection:purge-non-diffusible
                            {--dry-run : Montre ce qui serait supprimé, sans rien supprimer}
                            {--force : Assume la suppression, y compris au-delà du plafond}';

    protected $description = 'Supprime les fiches non diffusibles ([ND]) au sens INSEE.';

    public function handle(): int
    {
        $aSupprimer = DB::table('companies')->where('denomination', '[ND]')->count();
        $total = DB::table('companies')->count();

        if (! $this->suppressionAutorisee('companies', $aSupprimer, $total)) {
            return self::SUCCESS;
        }

        $supprimees = DB::table('companies')->where('denomination', '[ND]')->delete();
        $this->info("✅ {$supprimees} fiches non diffusibles supprimées.");

        return self::SUCCESS;
    }
}
