<?php

namespace App\Console\Commands;

use App\Console\Concerns\RefuseUneSuppressionMassive;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ne garde que les SOCIÉTÉS commerciales (cat. jur. 5xxx : SARL, SAS, SA, SNC, SCA…).
 * Supprime les entrepreneurs individuels/auto-entrepreneurs (1xxx), SCI (65xx),
 * associations (9xxx), administrations (7xxx), mutuelles/coopératives (6xxx), etc.
 * → base de prospection B2B propre. Suppression DÉFINITIVE.
 *
 * 🔴 CETTE COMMANDE SUPPRIMAIT SANS AUCUNE GARDE (audit 360, B15-004).
 *
 * Sa condition est `legal_form IS NULL OR left(legal_form, 1) <> '5'`. Le
 * `IS NULL` est le piège : sur la base de volume, la forme juridique est
 * inconnue pour l'essentiel des lignes — un `artisan
 * prospection:purge-non-commercial` lancé sans y penser effaçait donc
 * **presque toute la base**, sans confirmation ni retour possible.
 *
 * Le plafond de proportion refuse désormais ce cas et l'explique. Une purge
 * légitime reste faisable avec `--force` : elle devient un geste volontaire.
 */
class ProspectionPurgeNonCommercial extends Command
{
    use RefuseUneSuppressionMassive;

    protected $signature = 'prospection:purge-non-commercial
                            {--dry-run : Montre ce qui serait supprimé, sans rien supprimer}
                            {--force : Assume la suppression, y compris au-delà du plafond}';

    protected $description = 'Ne garde que les sociétés commerciales (5xxx). Supprime EI/AE, SCI, assos…';

    public function handle(): int
    {
        $condition = "(legal_form IS NULL OR left(legal_form, 1) <> '5')";

        $aSupprimer = DB::table('companies')->whereRaw($condition)->count();
        $total = DB::table('companies')->count();

        if (! $this->suppressionAutorisee('companies', $aSupprimer, $total)) {
            return self::SUCCESS;
        }

        $supprimees = DB::table('companies')->whereRaw($condition)->delete();
        $this->info("✅ {$supprimees} entités non-sociétés (auto-entrepreneurs, EI, SCI, associations…) supprimées.");

        return self::SUCCESS;
    }
}
