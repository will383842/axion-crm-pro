<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Ne garde que les SOCIÉTÉS commerciales (cat. jur. 5xxx : SARL, SAS, SA, SNC, SCA…).
 * Supprime les entrepreneurs individuels/auto-entrepreneurs (1xxx), SCI (65xx),
 * associations (9xxx), administrations (7xxx), mutuelles/coopératives (6xxx), etc.
 * → base de prospection B2B propre. Suppression définitive.
 */
class ProspectionPurgeNonCommercial extends Command
{
    protected $signature = 'prospection:purge-non-commercial';

    protected $description = 'Ne garde que les sociétés commerciales (5xxx). Supprime EI/AE, SCI, assos…';

    public function handle(): int
    {
        $condition = "(legal_form IS NULL OR left(legal_form, 1) <> '5')";
        $count = DB::table('companies')->whereRaw($condition)->count();
        DB::table('companies')->whereRaw($condition)->delete();
        $this->info("✅ {$count} entités non-sociétés (auto-entrepreneurs, EI, SCI, associations…) supprimées.");
        return self::SUCCESS;
    }
}
