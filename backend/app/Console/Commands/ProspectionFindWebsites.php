<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Étape 1 du pipeline d'enrichissement : trouver le SITE WEB de chaque entreprise
 * (uniquement — rien d'autre), par devinette de domaine (DNS + vérification contenu).
 *
 * Marque chaque entreprise : website_status = found | not_found, + website_method + date.
 * REPRENABLE : ne traite que les `pending`, donc on peut interrompre/relancer sans refaire.
 */
class ProspectionFindWebsites extends Command
{
    protected $signature = 'prospection:find-websites {--department=} {--limit=0} {--batch=100}';

    protected $description = 'Trouve le site web des entreprises (devinette) + marque le statut. Reprenable.';

    public function handle(DomainFinderService $finder): int
    {
        $limit = (int) $this->option('limit');
        $batch = max(50, (int) $this->option('batch'));
        $dept = $this->option('department');

        $processed = 0;
        $found = 0;
        $start = microtime(true);

        while (true) {
            $q = Company::query()
                ->where('website_status', 'pending')
                ->whereNull('website')
                ->whereNotNull('denomination');
            if ($dept) {
                $q->where('department_code', $dept);
            }
            $companies = $q->limit($batch)->get();
            if ($companies->isEmpty()) {
                break;
            }

            // Devinette CONCURRENTE (Http::pool) : tout le lot testé en parallèle.
            $urls = $finder->guessDomainsBatch($companies);

            $now = now();
            foreach ($companies as $c) {
                $url = $urls[$c->id] ?? null;
                DB::table('companies')->where('id', $c->id)->update([
                    'website'            => $url,
                    'website_status'     => $url ? 'found' : 'not_found',
                    'website_method'     => $url ? 'guess' : null,
                    'website_checked_at' => $now,
                    'updated_at'         => $now,
                ]);
                $processed++;
                if ($url) {
                    $found++;
                }
            }

            $elapsed = max(1, (int) round(microtime(true) - $start));
            $this->info("  … {$processed} traités · {$found} sites · " . round($processed / $elapsed, 1) . '/s');
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $pct = $processed > 0 ? round($found / $processed * 100, 1) : 0;
        $this->info("✅ Terminé (dépt " . ($dept ?: 'tous') . ") : {$processed} traités · {$found} sites trouvés ({$pct}%).");
        return self::SUCCESS;
    }
}
