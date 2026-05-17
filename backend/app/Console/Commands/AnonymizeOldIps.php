<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * RGPD : anonymisation des IPs dans audit_logs + sessions > 30 jours.
 * Tronque IPv4 au /24 (192.168.42.123 → 192.168.42.0) et IPv6 au /48.
 * Schedule daily à 04:30.
 */
class AnonymizeOldIps extends Command
{
    protected $signature = 'rgpd:anonymize-ips {--dry-run}';
    protected $description = 'Anonymise les IPs > 30 jours dans audit_logs + sessions';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays(30);

        $auditCount = $dryRun
            ? DB::table('audit_logs')->where('created_at', '<', $cutoff)->whereNotNull('ip')->count()
            : DB::statement(<<<SQL
                UPDATE audit_logs
                SET ip = (host(network(ip::cidr / CASE WHEN family(ip) = 4 THEN 24 ELSE 48 END)))::inet
                WHERE created_at < ? AND ip IS NOT NULL
                SQL, [$cutoff]);

        $sessionsCount = $dryRun
            ? DB::table('sessions')->where('last_activity', '<', $cutoff->timestamp)->whereNotNull('ip_address')->count()
            : DB::table('sessions')
                ->where('last_activity', '<', $cutoff->timestamp)
                ->whereNotNull('ip_address')
                ->update(['ip_address' => null]);

        $this->info("anonymise IPs : audit_logs=" . (is_bool($auditCount) ? 'updated' : $auditCount)
            . " sessions=" . $sessionsCount . " (dry-run=" . ($dryRun ? 'true' : 'false') . ")");
        return self::SUCCESS;
    }
}
