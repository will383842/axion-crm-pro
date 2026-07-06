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
 *
 * PASS 2 (`--retry`) : reprend les `not_found` avec des variantes de domaines étendues
 * (TLD alternatifs, acronyme, deux premiers mots…). Ceux toujours introuvables passent
 * à `exhausted` (statut terminal) → la pass 2 est elle aussi reprenable et termine.
 */
class ProspectionFindWebsites extends Command
{
    protected $signature = 'prospection:find-websites {--department=} {--limit=0} {--batch=100} {--retry}';

    protected $description = 'Trouve le site web des entreprises (devinette) + marque le statut. Reprenable. --retry = pass 2 sur les not_found.';

    public function handle(DomainFinderService $finder): int
    {
        $limit = (int) $this->option('limit');
        $batch = max(50, (int) $this->option('batch'));
        $dept = $this->option('department');
        $retry = (bool) $this->option('retry');

        // Pass 1 = pending → found/not_found. Pass 2 (--retry) = not_found → found/exhausted.
        $sourceStatus = $retry ? 'not_found' : 'pending';
        $missMethod = $retry ? 'guess2' : 'guess';

        $processed = 0;
        $found = 0;
        $start = microtime(true);

        while (true) {
            $q = Company::query()
                ->where('website_status', $sourceStatus)
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
            // En pass 2, on active les candidats étendus.
            $urls = $finder->guessDomainsBatch($companies, extended: $retry);

            $now = now();
            foreach ($companies as $c) {
                $url = $urls[$c->id] ?? null;
                // Pass 1 : miss → not_found (retentable en pass 2).
                // Pass 2 : miss → exhausted (terminal, plus jamais retenté).
                $missStatus = $retry ? 'exhausted' : 'not_found';
                DB::table('companies')->where('id', $c->id)->update([
                    'website'            => $url,
                    'website_status'     => $url ? 'found' : $missStatus,
                    'website_method'     => $url ? $missMethod : null,
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
        $pass = $retry ? 'pass 2' : 'pass 1';
        $this->info("✅ Terminé ({$pass}, dépt " . ($dept ?: 'tous') . ") : {$processed} traités · {$found} sites trouvés ({$pct}%).");
        return self::SUCCESS;
    }
}
