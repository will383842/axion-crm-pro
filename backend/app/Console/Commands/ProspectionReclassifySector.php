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

        // UNE seule passe : CASE sur LEFT(naf, 2) (les 2 premiers chars du NAF =
        // division, que le code ait un point « 41.20B » ou pas « 4120B »). Pas de
        // regex → rapide sur 209k lignes (l'ancienne version en 11 passes regex
        // dépassait le timeout du job).
        $cases = [];
        foreach (SectorClassifier::DIVISIONS as $sector => $divisions) {
            foreach ($divisions as $d) {
                $cases[] = "WHEN '{$d}' THEN '{$sector}'";
            }
        }
        $caseSql = 'CASE LEFT(naf, 2) ' . implode(' ', $cases) . " ELSE 'autre' END";
        $where = 'naf IS NOT NULL' . ($onlyNull ? " AND (sector_main IS NULL OR sector_main = '')" : '');

        $total = DB::update("UPDATE companies SET sector_main = {$caseSql} WHERE {$where}");

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
