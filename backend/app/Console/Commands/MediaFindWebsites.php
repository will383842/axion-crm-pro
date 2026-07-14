<?php

namespace App\Console\Commands;

use App\Models\Media;
use App\Services\Domain\DomainFinderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Enrichissement L3 des MÉDIAS : trouve le site web des médias sans URL (surtout
 * les titres CPPAP et agences, la plupart des médias NAF/SPEL ayant déjà un site)
 * par devinette de domaine — MÊME moteur {@see DomainFinderService} que les
 * entreprises (le modèle Media expose un alias `denomination` → `name`).
 *
 * REPRENABLE : ne traite que website_status='pending'. Le volume est petit
 * (~5k) → une seule exécution suffit, pas de service systemd nécessaire.
 */
class MediaFindWebsites extends Command
{
    protected $signature = 'media:find-websites {--limit=0} {--batch=100}';

    protected $description = 'Trouve le site web des médias (devinette de domaine). Reprenable.';

    public function handle(DomainFinderService $finder): int
    {
        $limit = (int) $this->option('limit');
        $batch = max(50, (int) $this->option('batch'));
        $processed = 0;
        $found = 0;
        $start = microtime(true);

        while (true) {
            $medias = Media::query()
                ->where('website_status', 'pending')
                ->whereNull('website')
                ->whereNotNull('name')
                // Anti-divergence : les médias LIÉS à une entreprise héritent du site
                // de celle-ci (media:sync-from-companies). On ne devine QUE pour les
                // médias autonomes (titres CPPAP, agences) sans entreprise.
                ->whereNull('company_id')
                ->limit($batch)
                ->get();
            if ($medias->isEmpty()) {
                break;
            }

            $urls = $finder->guessDomainsBatch($medias);
            [$bp, $bf] = $this->flushBatch($medias, $urls);
            $processed += $bp;
            $found += $bf;

            $elapsed = max(1, (int) round(microtime(true) - $start));
            $this->info("  … {$processed} traités · {$found} sites · " . round($processed / $elapsed, 1) . '/s');
            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        $pct = $processed > 0 ? round($found / $processed * 100, 1) : 0;
        $this->info("✅ Terminé : {$processed} traités · {$found} sites trouvés ({$pct}%).");

        return self::SUCCESS;
    }

    /**
     * Écrit tout le lot en UNE requête (bulk UPDATE ... FROM VALUES).
     *
     * @param  \Illuminate\Support\Collection<int, Media>  $medias
     * @param  array<int, string|null>  $urls
     * @return array{0:int,1:int}
     */
    private function flushBatch($medias, array $urls): array
    {
        $now = now()->format('Y-m-d H:i:sP');
        $rows = [];
        // Trois placeholders de tête (ordre du SQL) : website_checked_at,
        // enriched_at, updated_at.
        $bindings = [$now, $now, $now];
        $processed = 0;
        $found = 0;

        foreach ($medias as $m) {
            $url = $urls[$m->id] ?? null;
            $rows[] = '(?::bigint, ?::text, ?::text, ?::text)';
            $bindings[] = $m->id;
            $bindings[] = $url;
            $bindings[] = $url ? 'found' : 'not_found';
            $bindings[] = $url ? 'guess' : null;
            $processed++;
            if ($url) {
                $found++;
            }
        }

        if ($rows === []) {
            return [0, 0];
        }

        $values = implode(',', $rows);
        // Un site trouvé ⇒ média enrichi (COALESCE conserve le 1er enriched_at) ;
        // un not_found ne change pas enrich_status. Cohérent avec le backfill migration.
        $sql = "UPDATE media AS m
                SET website = v.website,
                    website_status = v.status,
                    website_method = v.method,
                    website_checked_at = ?::timestamptz,
                    enrich_status = CASE WHEN v.status = 'found' THEN 'enriched' ELSE m.enrich_status END,
                    enriched_at = CASE WHEN v.status = 'found' THEN COALESCE(m.enriched_at, ?::timestamptz) ELSE m.enriched_at END,
                    updated_at = ?::timestamptz
                FROM (VALUES {$values}) AS v(id, website, status, method)
                WHERE m.id = v.id";
        DB::update($sql, $bindings);

        return [$processed, $found];
    }
}
