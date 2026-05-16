<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Job nightly (02:00) — détection signaux business : levées de fonds, recrutement,
 * nominations, déménagements. Scanne INSEE Sirene + BODACC + France Travail.
 * Cf. spec/20_detection_nouveaux_prospects_signaux.md.
 */
class SignalsNightlyScan extends Command
{
    protected $signature = 'signals:nightly-scan';

    protected $description = 'Scanne sources officielles pour détecter nouveaux signaux business.';

    public function handle(): int
    {
        if (env('MOCK_MODE', true)) {
            $this->info('MOCK_MODE — signals scan no-op.');
            return self::SUCCESS;
        }

        $this->warn('Implémentation réelle prévue Sprint 7 — fan-out queues scrape:france-travail + scrape:bodacc.');
        return self::SUCCESS;
    }
}
