<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\Domain\DomainFinderService;
use Illuminate\Console\Command;

/**
 * Étape 1 du pipeline d'enrichissement : trouver le SITE WEB de chaque entreprise
 * (uniquement — rien d'autre), par devinette de domaine (DNS + vérification contenu).
 *
 * Marque chaque entreprise : website_status = found | not_found, + website_method + date.
 * REPRENABLE : ne traite que les `pending`, donc on peut interrompre/relancer sans refaire.
 */
class ProspectionFindWebsites extends Command
{
    protected $signature = 'prospection:find-websites {--department=} {--limit=0} {--batch=300}';

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

            foreach ($companies as $c) {
                $url = null;
                try {
                    $url = $finder->find($c);
                } catch (\Throwable $e) {
                    // réseau/TLS d'un site tiers → traité comme non trouvé, on continue.
                }
                $c->website = $url ?: null;
                $c->website_status = $url ? 'found' : 'not_found';
                $c->website_method = $url ? 'guess' : null;
                $c->website_checked_at = now();
                $c->save();

                $processed++;
                if ($url) {
                    $found++;
                }
                if ($limit > 0 && $processed >= $limit) {
                    break 2;
                }
            }

            $elapsed = max(1, (int) round(microtime(true) - $start));
            $rate = round($processed / $elapsed, 1);
            $this->info("  … {$processed} traités · {$found} sites trouvés · {$rate}/s");
        }

        $pct = $processed > 0 ? round($found / $processed * 100, 1) : 0;
        $this->info("✅ Terminé (dépt " . ($dept ?: 'tous') . ") : {$processed} traités · {$found} sites trouvés ({$pct}%).");
        return self::SUCCESS;
    }
}
