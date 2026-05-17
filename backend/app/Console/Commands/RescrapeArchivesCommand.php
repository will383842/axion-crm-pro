<?php

namespace App\Console\Commands;

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use Illuminate\Console\Command;

/**
 * Sprint H6 (2026-05-17) — Re-dispatch EnrichCompanyJob pour companies archivées
 * sans email depuis 30+ jours.
 *
 * Schedule monthlyOn(1, '02:00') déjà posé dans routes/console.php par Pipeline
 * 360° initial avec un skip() temporaire. Cette commande implémente le code
 * manquant — le schedule s'active automatiquement après deploy de ce commit.
 *
 * Throttle interne : 2 secondes de delay entre chaque dispatch (évite de
 * marteler INSEE / Brave en re-traitement mass).
 */
class RescrapeArchivesCommand extends Command
{
    /** @var string */
    protected $signature = 'companies:rescrape-archives
        {--limit=200 : Nombre max de companies à re-scraper en un run}
        {--workspace= : Workspace UUID cible (default: tous)}
        {--reason=no_email : archive_reason à cibler (no_email|low_quality_score|entreprise_radiee|duplicate|manual)}
        {--age-days=30 : Âge minimum depuis dernier updated_at}
        {--dry-run : Affiche les companies qui seraient dispatched sans exécuter}';

    /** @var string */
    protected $description = 'Re-dispatch EnrichCompanyJob pour companies archivées dépassant un certain âge';

    public function handle(): int
    {
        $limit     = (int) $this->option('limit');
        $workspace = $this->option('workspace');
        $reason    = (string) $this->option('reason');
        $ageDays   = (int) $this->option('age-days');
        $dryRun    = (bool) $this->option('dry-run');

        if ($limit <= 0 || $limit > 5000) {
            $this->error('limit doit être entre 1 et 5000');
            return self::INVALID;
        }

        $allowedReasons = ['no_email', 'low_quality_score', 'entreprise_radiee', 'duplicate', 'manual'];
        if (! in_array($reason, $allowedReasons, true)) {
            $this->error('reason doit être l\'un de : ' . implode(', ', $allowedReasons));
            return self::INVALID;
        }

        $query = Company::query()
            ->where('prospection_status', 'archived_no_email')
            ->where('archive_reason', $reason)
            ->where('updated_at', '<', now()->subDays($ageDays))
            ->orderBy('updated_at', 'asc')
            ->limit($limit);

        if ($workspace) {
            $query->where('workspace_id', $workspace);
        }

        $companies = $query->get(['id', 'workspace_id', 'siren', 'denomination']);
        $count = $companies->count();

        $this->info("Found {$count} companies (reason={$reason}, age>={$ageDays}d, limit={$limit})"
            . ($workspace ? " in workspace {$workspace}" : ' across all workspaces'));

        if ($dryRun) {
            $this->warn('--dry-run actif, aucun job dispatched.');
            foreach ($companies as $c) {
                $this->line(sprintf('  - #%d siren=%s %s', $c->id, $c->siren, $c->denomination));
            }
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Rien à re-scraper. Bye.');
            return self::SUCCESS;
        }

        // Throttle 2s entre dispatches pour ne pas marteler INSEE / Brave
        $offsetSeconds = 0;
        foreach ($companies as $company) {
            EnrichCompanyJob::dispatch($company->id)
                ->delay(now()->addSeconds($offsetSeconds))
                ->onQueue('default');
            $offsetSeconds += 2;
        }

        $this->info(sprintf(
            'Dispatched %d EnrichCompanyJob avec 2s spacing (dernier delay ~%ds)',
            $count,
            $offsetSeconds,
        ));

        return self::SUCCESS;
    }
}
