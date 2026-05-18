<?php

namespace App\Console\Commands;

use App\Jobs\EnrichCompanyJob;
use App\Models\Company;
use App\Services\Scraping\GooglePlacesClient;
use Illuminate\Console\Command;

/**
 * Sprint H12 (2026-05-18) — Re-dispatch EnrichCompanyJob pour les companies
 * qui n'ont pas été enrichies par Google Places à cause du quota mensuel.
 *
 * Workflow :
 *  1. Au cours du mois, GooglePlacesClient marque signals.google_places_pending=true
 *     dès que le quota mensuel free ($200 crédit ≈ 11500 calls) est atteint.
 *  2. Le 1er de chaque mois suivant, cette commande retraite les pending.
 *
 * Schedule recommandé : Schedule::command('companies:retry-google-places --limit=500')
 *                         ->monthlyOn(1, '03:00')->withoutOverlapping();
 */
class RetryGooglePlacesCommand extends Command
{
    /** @var string */
    protected $signature = 'companies:retry-google-places
        {--limit=500 : Nombre max de companies à retraiter en un run}
        {--workspace= : Workspace UUID cible (default: tous)}
        {--dry-run : Affiche les companies qui seraient dispatched sans exécuter}';

    /** @var string */
    protected $description = 'Re-dispatch EnrichCompanyJob pour companies google_places_pending (quota mensuel)';

    public function handle(GooglePlacesClient $places): int
    {
        $limit     = (int) $this->option('limit');
        $workspace = $this->option('workspace');
        $dryRun    = (bool) $this->option('dry-run');

        if ($limit <= 0 || $limit > 5000) {
            $this->error('limit doit être entre 1 et 5000');
            return self::INVALID;
        }

        // Avant de relancer, on vérifie qu'il reste du quota — sinon ça sert à rien
        $used  = $places->currentMonthUsage();
        $total = $places->monthlyQuotaLimit();
        $remaining = max(0, $total - $used);
        $this->info("Google Places quota ce mois : {$used} / {$total} utilisé ({$remaining} restant)");

        if ($remaining <= 0) {
            $this->warn('Aucun quota restant ce mois-ci. Re-lance la commande après le 1er du mois prochain.');
            return self::SUCCESS;
        }

        // Limite effective = min(limit demandé, quota restant)
        $effectiveLimit = min($limit, $remaining);
        if ($effectiveLimit < $limit) {
            $this->info("Limite ajustée à {$effectiveLimit} pour ne pas dépasser le quota restant.");
        }

        // Companies en pending Google Places
        $query = Company::query()
            ->whereRaw("(signals->'google_places_pending') IS NOT NULL")
            ->whereRaw("(signals->'google_places'->>'enriched_at') IS NULL")
            ->orderBy('updated_at', 'asc')
            ->limit($effectiveLimit);

        if ($workspace) {
            $query->where('workspace_id', $workspace);
        }

        $companies = $query->get(['id', 'workspace_id', 'siren', 'denomination']);
        $count = $companies->count();

        $this->info("Found {$count} companies pending Google Places enrichment"
            . ($workspace ? " in workspace {$workspace}" : ' across all workspaces'));

        if ($dryRun) {
            $this->warn('--dry-run actif, aucun job dispatched.');
            foreach ($companies as $c) {
                $this->line(sprintf('  - #%d siren=%s %s', $c->id, $c->siren, $c->denomination));
            }
            return self::SUCCESS;
        }

        if ($count === 0) {
            $this->info('Aucune company en attente. Bye.');
            return self::SUCCESS;
        }

        // Throttle 2s entre dispatches pour étaler la charge Google Places
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
