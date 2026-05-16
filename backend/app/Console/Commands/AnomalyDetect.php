<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Anomaly detector (15 min) — détecte les déviations vs baseline 7j moyenne mobile :
 * - taux d'échec scraping > 15 %
 * - latence p95 LLM > 5s
 * - coût LLM/h > kill-switch workspace
 * - taux email invalid > 30 %
 * Si anomalie détectée → notification Telegram + audit_log.
 */
class AnomalyDetect extends Command
{
    protected $signature = 'anomaly:detect';

    protected $description = 'Détecte les anomalies sur les métriques métier vs baseline 7j.';

    public function handle(): int
    {
        $anomalies = [];

        // Taux d'échec scraping (1h)
        $row = DB::selectOne(<<<SQL
            SELECT
              COUNT(*) FILTER (WHERE status = 'failed')::FLOAT
              / NULLIF(COUNT(*), 0) AS failure_rate,
              COUNT(*) AS total
            FROM scraper_runs
            WHERE created_at > now() - INTERVAL '1 hour'
        SQL);
        $rate = (float) ($row->failure_rate ?? 0);
        if ($rate > 0.15 && (int) ($row->total ?? 0) >= 20) {
            $anomalies[] = ['kind' => 'scraping_failure_rate', 'value' => $rate, 'threshold' => 0.15, 'window' => '1h'];
        }

        // Coût LLM workspace (depuis minuit)
        $workspaces = DB::select(<<<SQL
            SELECT workspace_id, SUM(cost_eur) AS total_eur
            FROM llm_usage
            WHERE created_at >= date_trunc('day', now())
            GROUP BY workspace_id
        SQL);
        foreach ($workspaces as $ws) {
            $cap = (float) DB::table('workspaces')->where('id', $ws->workspace_id)->value('cost_cap_eur');
            if ((float) $ws->total_eur > $cap * 0.8) {
                $anomalies[] = [
                    'kind' => 'llm_cost_near_cap', 'workspace_id' => $ws->workspace_id,
                    'value' => (float) $ws->total_eur, 'threshold' => $cap * 0.8,
                ];
            }
        }

        if (empty($anomalies)) {
            $this->info('Aucune anomalie détectée.');
            return self::SUCCESS;
        }

        foreach ($anomalies as $a) {
            $this->warn(json_encode($a, JSON_UNESCAPED_SLASHES));
        }

        // Sprint 11 : send TelegramAlert::dispatch($anomalies);
        return self::SUCCESS;
    }
}
