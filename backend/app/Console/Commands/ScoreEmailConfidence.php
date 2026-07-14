<?php

namespace App\Console\Commands;

use App\Services\Email\EmailConfidenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Calcule le score de confiance email DÉTERMINISTE A/B/C (audit deep fixes
 * 2026-07-14) sur les contacts, puis reporte la meilleure confiance au niveau
 * société (companies.best_email_confidence).
 *
 * Pas de SMTP, pas d'I/O réseau : score purement calculé (EmailConfidenceService)
 * en comparant le domaine de l'email au domaine racine du site de l'entreprise.
 *
 * REPRENABLE : par défaut ne traite que `email_confidence IS NULL` (curseur par
 * id → mémoire bornée, relançable sans refaire). `--refresh` recalcule tout.
 *
 * SHARDING (`--shards=N --shard=k`) : ne traite que les lignes `id % N == k`,
 * pour exécuter N instances EN PARALLÈLE sans chevauchement (calqué sur
 * prospection:find-websites).
 */
class ScoreEmailConfidence extends Command
{
    protected $signature = 'prospection:score-email-confidence
        {--shards=1 : Nombre de partitions (exécution distribuée)}
        {--shard=0 : Index de cette partition [0, shards-1]}
        {--limit=0 : Nombre max de contacts traités (0 = illimité)}
        {--refresh : Recalcule TOUT (ignore email_confidence déjà posé)}';

    protected $description = 'Score de confiance email A/B/C (déterministe, sans SMTP) sur contacts + best_email_confidence société. Reprenable, shardable.';

    private const BATCH = 1000;

    public function handle(EmailConfidenceService $scorer): int
    {
        $shards = max(1, (int) $this->option('shards'));
        $shard = (int) $this->option('shard');
        $limit = max(0, (int) $this->option('limit'));
        $refresh = (bool) $this->option('refresh');

        if ($shard < 0 || $shard >= $shards) {
            $this->error("--shard doit être dans [0, {$shards}-1].");

            return self::FAILURE;
        }

        $scope = $shards > 1 ? "shard {$shard}/{$shards}" : 'global';
        $this->info("Scoring email confidence ({$scope}, " . ($refresh ? 'refresh' : 'incrémental') . ')…');

        $contacts = $this->scoreContacts($scorer, $shards, $shard, $limit, $refresh);
        $companies = $this->scoreCompanies($scorer, $shards, $shard, $refresh);

        $this->info("✅ Terminé : {$contacts} contacts scorés · {$companies} sociétés (best_email_confidence).");

        return self::SUCCESS;
    }

    /** Phase 1 — score chaque contact (email vs site de sa société). */
    private function scoreContacts(EmailConfidenceService $scorer, int $shards, int $shard, int $limit, bool $refresh): int
    {
        $now = now()->format('Y-m-d H:i:sP');
        $lastId = 0;
        $processed = 0;

        while (true) {
            $rows = DB::table('contacts as ct')
                ->leftJoin('companies as co', 'co.id', '=', 'ct.company_id')
                ->whereNotNull('ct.email')
                ->where('ct.id', '>', $lastId)
                ->when(! $refresh, fn ($q) => $q->whereNull('ct.email_confidence'))
                ->when($shards > 1, fn ($q) => $q->whereRaw('ct.id % ? = ?', [$shards, $shard]))
                ->orderBy('ct.id')
                ->limit(self::BATCH)
                ->get(['ct.id', 'ct.email', 'co.website']);

            if ($rows->isEmpty()) {
                break;
            }

            $values = [];
            $bindings = [$now];
            foreach ($rows as $row) {
                $conf = $scorer->score((string) $row->email, $row->website !== null ? (string) $row->website : null);
                $values[] = '(?::bigint, ?::char)';
                $bindings[] = $row->id;
                $bindings[] = $conf; // null autorisé (CHECK IN A/B/C ou NULL)
            }

            $sql = 'UPDATE contacts AS c SET email_confidence = v.conf, updated_at = ?::timestamptz '
                . 'FROM (VALUES ' . implode(',', $values) . ') AS v(id, conf) WHERE c.id = v.id';
            DB::update($sql, $bindings);

            $processed += $rows->count();
            $lastId = (int) $rows->last()->id;
            $this->line("  … {$processed} contacts scorés");

            if ($limit > 0 && $processed >= $limit) {
                break;
            }
        }

        return $processed;
    }

    /**
     * Phase 2 — best_email_confidence société = meilleure confiance (A>B>C)
     * parmi ses contacts + son email_generic.
     */
    private function scoreCompanies(EmailConfidenceService $scorer, int $shards, int $shard, bool $refresh): int
    {
        $now = now()->format('Y-m-d H:i:sP');
        $lastId = 0;
        $processed = 0;

        while (true) {
            $companies = DB::table('companies as co')
                ->where('co.id', '>', $lastId)
                ->when($shards > 1, fn ($q) => $q->whereRaw('co.id % ? = ?', [$shards, $shard]))
                ->when(! $refresh, fn ($q) => $q->whereNull('co.best_email_confidence'))
                // Seules les sociétés porteuses d'une source email sont pertinentes.
                ->where(function ($q) {
                    $q->whereNotNull('co.email_generic')
                        ->orWhereExists(function ($sub) {
                            $sub->select(DB::raw(1))
                                ->from('contacts')
                                ->whereColumn('contacts.company_id', 'co.id')
                                ->whereNotNull('contacts.email');
                        });
                })
                ->orderBy('co.id')
                ->limit(self::BATCH)
                ->get(['co.id', 'co.email_generic', 'co.website']);

            if ($companies->isEmpty()) {
                break;
            }

            $ids = $companies->pluck('id')->all();
            $contactConf = DB::table('contacts')
                ->whereIn('company_id', $ids)
                ->whereNotNull('email_confidence')
                ->get(['company_id', 'email_confidence'])
                ->groupBy('company_id');

            $values = [];
            $bindings = [$now];
            foreach ($companies as $co) {
                $ranks = [];
                foreach ($contactConf->get($co->id, collect()) as $c) {
                    $ranks[] = $this->rank((string) $c->email_confidence);
                }
                if ($co->email_generic !== null && $co->email_generic !== '') {
                    $gc = $scorer->score((string) $co->email_generic, $co->website !== null ? (string) $co->website : null);
                    if ($gc !== null) {
                        $ranks[] = $this->rank($gc);
                    }
                }
                $best = empty($ranks) ? null : $this->char(min($ranks));
                $values[] = '(?::bigint, ?::char)';
                $bindings[] = $co->id;
                $bindings[] = $best;
            }

            $sql = 'UPDATE companies AS c SET best_email_confidence = v.conf, updated_at = ?::timestamptz '
                . 'FROM (VALUES ' . implode(',', $values) . ') AS v(id, conf) WHERE c.id = v.id';
            DB::update($sql, $bindings);

            $processed += $companies->count();
            $lastId = (int) $companies->last()->id;
        }

        return $processed;
    }

    /** A=1, B=2, C=3 (plus petit = meilleur). Défaut prudent (C) si inconnu. */
    private function rank(string $conf): int
    {
        return match ($conf) {
            'A' => 1,
            'B' => 2,
            default => 3,
        };
    }

    private function char(int $rank): string
    {
        return match ($rank) {
            1 => 'A',
            2 => 'B',
            default => 'C',
        };
    }
}
