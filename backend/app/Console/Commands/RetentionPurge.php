<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Politique de rétention RGPD :
 * - audit_logs > 24 mois         → archivage S3 + suppression (pg_partman gère le detach)
 * - email_validations expirées   → suppression
 * - scraper_runs > 90 jours      → suppression payload_path + response_payload (garde meta)
 * - llm_usage > 12 mois          → archivage + suppression
 * - notifications > 90 jours     → suppression
 */
class RetentionPurge extends Command
{
    protected $signature = 'retention:purge {--dry-run}';

    protected $description = 'Applique la politique de rétention RGPD aux tables transversales.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $this->info('Retention purge — dry-run=' . ($dryRun ? 'true' : 'false'));

        $tasks = [
            'email_validations expirées' =>
                "DELETE FROM email_validations WHERE expires_at < now() - INTERVAL '7 days'",
            'notifications anciennes (>90j)' =>
                "DELETE FROM notifications WHERE created_at < now() - INTERVAL '90 days'",
            'scraper_runs payload (>90j)' =>
                "UPDATE scraper_runs SET response_payload = NULL, payload_path = NULL
                 WHERE created_at < now() - INTERVAL '90 days' AND response_payload IS NOT NULL",
        ];

        foreach ($tasks as $name => $sql) {
            if ($dryRun) {
                // Compter ce qui serait supprimé
                $explainSql = preg_replace('/^DELETE FROM (\w+)/', 'SELECT COUNT(*) AS c FROM $1', $sql);
                $explainSql = preg_replace('/^UPDATE (\w+) SET .* WHERE/', 'SELECT COUNT(*) AS c FROM $1 WHERE', $explainSql);
                $count = DB::selectOne($explainSql)->c ?? 0;
                $this->line("  - {$name} : {$count} lignes seraient affectées");
            } else {
                $affected = DB::affectingStatement($sql);
                $this->info("  ✓ {$name} : {$affected} lignes traitées");
            }
        }

        return self::SUCCESS;
    }
}
