<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Refresh la materialized view `coverage_matrix_cells`.
 * Schedulé hourly (cf. routes/console.php).
 */
class CoverageRefreshMatrix extends Command
{
    protected $signature = 'coverage:refresh-matrix {--concurrent : utilise REFRESH CONCURRENTLY}';

    protected $description = 'Rafraîchit la materialized view coverage_matrix_cells (rollup hourly).';

    public function handle(): int
    {
        $concurrent = (bool) $this->option('concurrent');
        $start = microtime(true);

        DB::statement($concurrent
            ? 'REFRESH MATERIALIZED VIEW CONCURRENTLY coverage_matrix_cells'
            : 'REFRESH MATERIALIZED VIEW coverage_matrix_cells');

        $latencyMs = (int) ((microtime(true) - $start) * 1000);
        $this->info("coverage_matrix_cells refresh OK ({$latencyMs} ms, concurrent=" . ($concurrent ? 'true' : 'false') . ')');
        return self::SUCCESS;
    }
}
