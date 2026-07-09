<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Marque les émissions TV/radio « ACTUELLES » vs « DISPARUES » via Wikidata.
 *
 * Les émissions importées depuis Wikidata portent `socials->>'wikidata_id'` (leur
 * QID). L'import initial ne récupère PAS la date de fin de diffusion (P582) : une
 * émission arrêtée reste indistinguable d'une émission en cours. Cette commande
 * interroge le SPARQL Wikidata pour la propriété `P582` (« date de fin ») par lots
 * de QID (`VALUES { wd:QID … }`, ~200/requête) et stocke le résultat dans `socials` :
 *
 *   - `socials.ended_at`                 = date de fin (YYYY-MM-DD) si P582 existe,
 *                                          ABSENT si l'émission est toujours en cours
 *   - `socials.wikidata_status_checked_at` = horodatage du contrôle (reprise/idempotence)
 *
 * Une émission est « actuelle » si PAS de `ended_at` OU `ended_at > aujourd'hui`,
 * sinon « terminée ». AUCUNE nouvelle colonne : le jsonb `socials` suffit, et le
 * `wikidata_id` (comme toute autre clé) est préservé au merge.
 *
 * Reprenable (`--limit`) : les émissions non encore contrôlées passent EN PREMIER
 * (tri `wikidata_status_checked_at NULLS FIRST`), donc des runs successifs balaient
 * tout le stock. `--dry-run` compte sans écrire. Gère le 429 (User-Agent descriptif
 * obligatoire selon la WMF User-Agent policy — sinon 403).
 */
class MediaTagEmissionsStatus extends Command
{
    protected $signature = 'media:tag-emissions-status {--limit=0 : Nombre max d\'émissions à contrôler ce run (0 = toutes)} {--dry-run : Compte sans écrire en base} {--workspace= : UUID du workspace cible}';

    protected $description = 'Marque les émissions Wikidata actuelles/disparues via la date de fin P582 (SPARQL).';

    private const SPARQL_ENDPOINT = 'https://query.wikidata.org/sparql';

    private const USER_AGENT = 'AxionCRM/1.0 (contact@axion-ia.com)';

    /** QID récupérés par requête SPARQL (VALUES). */
    private const BATCH_SIZE = 200;

    public function handle(): int
    {
        $limit = max(0, (int) $this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $workspaceId = $this->option('workspace') ?: Workspace::query()->orderBy('created_at')->value('id');
        if (! $workspaceId) {
            $this->error('Aucun workspace cible (base vide ?). Passez --workspace=UUID.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->warn('DRY-RUN : aucune écriture en base.');
        }

        // Émissions à contrôler — non-contrôlées d'abord (reprise), bornées par --limit.
        $query = DB::table('media')
            ->select('id', 'socials')
            ->where('workspace_id', $workspaceId)
            ->where('media_type', 'tv_emission')
            ->whereNull('deleted_at')
            ->whereRaw("socials->>'wikidata_id' IS NOT NULL")
            ->orderByRaw("(socials->>'wikidata_status_checked_at') NULLS FIRST")
            ->orderBy('id');
        if ($limit > 0) {
            $query->limit($limit);
        }
        $emissions = $query->get();

        if ($emissions->isEmpty()) {
            $this->info('Aucune émission Wikidata à contrôler.');

            return self::SUCCESS;
        }

        $today = Carbon::today();
        $stats = ['checked' => 0, 'ended' => 0, 'current' => 0];

        foreach ($emissions->chunk(self::BATCH_SIZE) as $batch) {
            // qid → id media (un QID peut n'apparaître qu'une fois — dédup import garanti).
            $qidToId = [];
            foreach ($batch as $row) {
                $socials = $this->decodeSocials($row->socials);
                $qid = $socials['wikidata_id'] ?? null;
                if (is_string($qid) && preg_match('/^Q\d+$/', $qid)) {
                    $qidToId[$qid] = ['id' => (int) $row->id, 'socials' => $socials];
                }
            }
            if ($qidToId === []) {
                continue;
            }

            $endMap = $this->fetchEndDates(array_keys($qidToId));
            if ($endMap === null) {
                $this->warn('Arrêt anticipé (rate limit / erreur HTTP) — reprise au prochain run.');
                break;
            }

            foreach ($qidToId as $qid => $meta) {
                $endedAt = $endMap[$qid] ?? null; // 'YYYY-MM-DD' ou null (toujours en cours)
                $isEnded = $endedAt !== null && Carbon::parse($endedAt)->lte($today);

                $stats['checked']++;
                $isEnded ? $stats['ended']++ : $stats['current']++;

                if ($dryRun) {
                    continue;
                }

                // Merge non destructif : on préserve wikidata_id + toute autre clé.
                $socials = $meta['socials'];
                if ($endedAt !== null) {
                    $socials['ended_at'] = $endedAt;
                } else {
                    unset($socials['ended_at']); // toujours en cours → pas de date de fin
                }
                $socials['wikidata_status_checked_at'] = now()->toIso8601String();

                DB::table('media')->where('id', $meta['id'])->update([
                    'socials' => json_encode($socials, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now(),
                ]);
            }

            // Politesse : Wikidata limite ~5 req/s par agent.
            usleep(300_000);
        }

        $this->newLine();
        $this->info(sprintf(
            '✓ %d émissions contrôlées : %d terminées (disparues), %d actuelles.',
            $stats['checked'],
            $stats['ended'],
            $stats['current'],
        ));
        if ($dryRun) {
            $this->warn('DRY-RUN : rien n\'a été persisté.');
        }

        return self::SUCCESS;
    }

    /**
     * Interroge Wikidata pour la date de fin (P582) d'un lot de QID.
     *
     * @param  array<int,string>  $qids
     * @return array<string,string>|null qid → 'YYYY-MM-DD' (date de fin la plus tardive),
     *                                   ou null en cas d'échec HTTP / 429.
     */
    private function fetchEndDates(array $qids): ?array
    {
        $values = implode(' ', array_map(fn (string $q) => 'wd:' . $q, $qids));
        $query = <<<SPARQL
            SELECT ?prog ?end WHERE {
              VALUES ?prog { {$values} }
              ?prog wdt:P582 ?end .
            }
            SPARQL;

        try {
            $resp = Http::withHeaders([
                'User-Agent' => self::USER_AGENT,
                'Accept' => 'application/sparql-results+json',
            ])->timeout(90)->retry(3, 3000, throw: false)->get(self::SPARQL_ENDPOINT, [
                'query' => $query,
                'format' => 'json',
            ]);
        } catch (\Throwable $e) {
            $this->error('Exception HTTP Wikidata : ' . $e->getMessage());

            return null;
        }

        if ($resp->status() === 429) {
            $this->error('Wikidata a renvoyé 429 (rate limit).');

            return null;
        }
        if (! $resp->successful()) {
            $this->error("Échec SPARQL HTTP {$resp->status()}.");

            return null;
        }

        return $this->parseEndDates($resp->json());
    }

    /**
     * Parse la réponse SPARQL en map qid → date de fin (YYYY-MM-DD). Si un QID porte
     * plusieurs P582, on retient la date la PLUS TARDIVE (fin réelle de diffusion).
     *
     * @param  array<string,mixed>|null  $json
     * @return array<string,string>
     */
    private function parseEndDates(?array $json): array
    {
        $bindings = is_array($json) ? ($json['results']['bindings'] ?? []) : [];
        $out = [];
        foreach ((array) $bindings as $b) {
            $progUri = $b['prog']['value'] ?? null;
            $end = $b['end']['value'] ?? null;
            if (! is_string($progUri) || ! is_string($end) || $end === '') {
                continue;
            }
            $qid = $this->qidFromUri($progUri);
            $date = substr($end, 0, 10); // '1998-08-31T00:00:00Z' → '1998-08-31'
            if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            if (! isset($out[$qid]) || $date > $out[$qid]) {
                $out[$qid] = $date;
            }
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeSocials(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function qidFromUri(string $uri): string
    {
        $parts = explode('/', $uri);

        return end($parts);
    }
}
