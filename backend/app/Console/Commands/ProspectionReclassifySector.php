<?php

namespace App\Console\Commands;

use App\Services\Prospection\SectorClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill : classe le secteur d'activité (btp, sante, commerce…) des entreprises
 * déjà récupérées, depuis le code NAF. Bulk SQL (instantané), sans re-collecter.
 * Mapping = source de vérité {@see SectorClassifier}.
 */
class ProspectionReclassifySector extends Command
{
    protected $signature = 'prospection:reclassify-sector {--all : reclasse aussi celles déjà classées}';

    protected $description = 'Classe le secteur d\'activité (BTP, santé, commerce…) depuis le code NAF.';

    public function handle(): int
    {
        $onlyNull = ! $this->option('all');
        // Division NAF = 2 premiers chiffres du code nettoyé.
        $div = "LEFT(regexp_replace(COALESCE(naf, ''), '[^0-9]', '', 'g'), 2)";
        $nullFilter = $onlyNull ? " AND (sector_main IS NULL OR sector_main = '')" : '';

        $total = 0;
        foreach (SectorClassifier::DIVISIONS as $sector => $divisions) {
            $in = "'" . implode("','", $divisions) . "'";
            $total += DB::update(
                "UPDATE companies SET sector_main = ? WHERE {$div} IN ({$in}){$nullFilter}",
                [$sector],
            );
        }
        // Tout le reste (NAF présent mais hors mapping) → 'autre'.
        $total += DB::update("UPDATE companies SET sector_main = 'autre' WHERE naf IS NOT NULL{$nullFilter}");

        $this->info("✅ {$total} entreprises classées par secteur.");
        foreach (
            DB::table('companies')
                ->selectRaw("COALESCE(sector_main, '(non classé)') AS s, COUNT(*) AS c")
                ->groupBy('s')->orderByDesc('c')->get() as $r
        ) {
            $this->line("   secteur {$r->s} : {$r->c}");
        }
        return self::SUCCESS;
    }
}
