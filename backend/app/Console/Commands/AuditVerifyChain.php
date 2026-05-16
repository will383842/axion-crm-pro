<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditHashChain;
use Illuminate\Console\Command;

class AuditVerifyChain extends Command
{
    protected $signature = 'audit:verify-chain {--max=}';

    protected $description = 'Vérifie l\'intégrité de la chaîne cryptographique des audit_logs.';

    public function handle(AuditHashChain $chain): int
    {
        $max = $this->option('max') !== null ? (int) $this->option('max') : null;
        $valid = $chain->verifyChain($max);

        if ($valid) {
            $this->info('Audit hash chain OK — aucune anomalie détectée.');
            return self::SUCCESS;
        }

        $this->error('Audit hash chain INVALIDE — possible falsification détectée.');
        // En prod : envoi Slack/Telegram + ouverture incident.
        return self::FAILURE;
    }
}
