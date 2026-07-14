<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * retention:prune-scraper-runs — borne la rétention de la table scraper_runs.
 *
 * scraper_runs journalise chaque appel d'enrichissement (≈ 7,6 M lignes constatées en
 * prod). On purge par lots les runs plus vieux que --days (défaut 90) pour ne pas
 * verrouiller la table. Suppression Postgres-safe (pas de DELETE ... LIMIT natif) via
 * sous-sélection d'ids.
 */
class PruneScraperRuns extends Command
{
    protected $signature = 'retention:prune-scraper-runs {--days=90} {--chunk=50000}';

    protected $description = 'Purge les scraper_runs plus vieux que N jours (défaut 90).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $chunk = max(1000, (int) $this->option('chunk'));
        $cutoff = now()->subDays($days);
        $total = 0;

        do {
            $ids = DB::table('scraper_runs')
                ->where('created_at', '<', $cutoff)
                ->limit($chunk)
                ->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $total += DB::table('scraper_runs')->whereIn('id', $ids)->delete();
            $this->line("… {$total} runs purgés");
        } while ($ids->count() === $chunk);

        $this->info("scraper_runs : {$total} lignes purgées (> {$days} j).");

        return self::SUCCESS;
    }
}
