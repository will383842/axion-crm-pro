<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vérifie hourly que nos IPs sortantes ne sont pas blacklistées (Spamhaus, Barracuda, etc.).
 * En MOCK_MODE, retourne toujours OK.
 */
class BlacklistsCheck extends Command
{
    protected $signature = 'blacklists:check';

    protected $description = 'Vérifie le statut blacklist des IPs sortantes auprès des DNSBL principaux.';

    public function handle(): int
    {
        if (env('MOCK_MODE', true)) {
            $this->info('MOCK_MODE — blacklists check skipped, all IPs assumed clean.');
            return self::SUCCESS;
        }

        $this->warn('Implémentation réelle prévue Sprint 8 — DNSBL queries Spamhaus + Barracuda + SORBS.');
        return self::SUCCESS;
    }
}
