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
 *
 * SHARDING (`--shards=N --shard=k`) : ne traite que les entreprises `id % N == k`.
 * Permet d'exécuter N instances EN PARALLÈLE sur des machines distinctes (runners
 * GitHub) sans chevauchement — chaque instance fait le domain-guessing sur SA machine
 * → débit ≈ ×N. Voir le workflow `prospection-find-websites-distributed.yml`.
 */
class ProspectionFindWebsites extends Command
{
    protected $signature = 'prospection:find-websites {--department=} {--limit=0} {--batch=100} {--retry} {--shard=} {--shards=}';

    protected $description = 'Trouve le site web des entreprises (devinette) + marque le statut. Reprenable. --retry = pass 2 ; --shard/--shards = exécution distribuée.';

    public function handle(DomainFinderService $finder): int
    {
        $limit = (int) $this->option('limit');
        $batch = max(50, (int) $this->option('batch'));
        $dept = $this->option('department');
        $retry = (bool) $this->option('retry');

        // Sharding optionnel : id % shards == shard (partition sans chevauchement).
        $shards = $this->option('shards') !== null ? max(1, (int) $this->option('shards')) : null;
        $shard = $this->option('shard') !== null ? (int) $this->option('shard') : null;
        if ($shards !== null && ($shard === null || $shard < 0 || $shard >= $shards)) {
            $this->error("--shard doit être dans [0, {$shards}-1] quand --shards est fourni.");

            return self::FAILURE;
        }

        // Pass 1 = pending → found/not_found. Pass 2 (--retry) = not_found → found/exhausted.
        $sourceStatus = $retry ? 'not_found' : 'pending';
        $missStatus = $retry ? 'exhausted' : 'not_found';
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
            if ($shards !== null) {
                $q->whereRaw('id % ? = ?', [$shards, $shard]);
            }
            $companies = $q->limit($batch)->get();
            if ($companies->isEmpty()) {
                break;
            }

            // Devinette CONCURRENTE (Http::pool) : tout le lot testé en parallèle.
            // En pass 2, on active les candidats étendus.
            $urls = $finder->guessDomainsBatch($companies, extended: $retry);

            [$batchProcessed, $batchFound] = $this->flushBatch($companies, $urls, $missStatus, $missMethod);
            $processed += $batchProcessed;
            $found += $batchFound;

            $elapsed = max(1, (int) round(microtime(true) - $start));
            $this->info("  … {$processed} traités · {$found} sites · " . round($processed / $elapsed, 1) . '/s');
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $pct = $processed > 0 ? round($found / $processed * 100, 1) : 0;
        $pass = $retry ? 'pass 2' : 'pass 1';
        $scope = $shards !== null ? "shard {$shard}/{$shards}" : 'dépt ' . ($dept ?: 'tous');
        $this->info("✅ Terminé ({$pass}, {$scope}) : {$processed} traités · {$found} sites trouvés ({$pct}%).");

        return self::SUCCESS;
    }

    /**
     * Écrit tout le lot en UNE seule requête (bulk UPDATE ... FROM VALUES), au lieu
     * de 100 UPDATE séparés. Crucial en exécution distribuée où la DB est jointe via
     * un tunnel SSH : 100 round-trips/lot (~30-50 ms chacun) plomberaient le débit.
     *
     * @param  \Illuminate\Support\Collection<int, Company>  $companies
     * @param  array<int, string|null>  $urls
     * @return array{0:int,1:int}  [traités, trouvés]
     */
    private function flushBatch($companies, array $urls, string $missStatus, string $missMethod): array
    {
        $now = now()->format('Y-m-d H:i:sP');
        $rows = [];
        $bindings = [$now, $now];
        $processed = 0;
        $found = 0;

        foreach ($companies as $c) {
            $url = $urls[$c->id] ?? null;
            $rows[] = '(?::bigint, ?::text, ?::text, ?::text)';
            $bindings[] = $c->id;
            $bindings[] = $url;
            $bindings[] = $url ? 'found' : $missStatus;
            $bindings[] = $url ? $missMethod : null;
            $processed++;
            if ($url) {
                $found++;
            }
        }

        if ($rows === []) {
            return [0, 0];
        }

        $values = implode(',', $rows);
        // Les 2 placeholders timestamp (SET) apparaissent AVANT ceux du FROM (VALUES) :
        // l'ordre des bindings doit donc être [now, now, ...lignes].
        $sql = "UPDATE companies AS c
                SET website = v.website,
                    website_status = v.status,
                    website_method = v.method,
                    website_checked_at = ?::timestamptz,
                    updated_at = ?::timestamptz
                FROM (VALUES {$values}) AS v(id, website, status, method)
                WHERE c.id = v.id";
        DB::update($sql, $bindings);

        return [$processed, $found];
    }
}
