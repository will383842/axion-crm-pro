<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Audit append-only avec chaîne cryptographique vérifiable.
 *   hash_n = sha256( hash_(n-1) || canonical_json(row_n) || secret )
 *
 * INVARIANT CENTRAL : la forme canonicalisée à l'ÉCRITURE doit être exactement
 * celle recalculée à la RELECTURE. C'est le contrat que `canonical()` garantit :
 * il accepte la forme « colonnes de la table » (event_type / status_code) et
 * tolère la forme « appelant » (method / status) pour rester rétro-compatible.
 * Sans cet alignement, `verifyChain()` retourne false sur une chaîne pourtant
 * intacte — et l'alerte d'intégrité ne veut plus rien dire.
 */
class AuditHashChain
{
    /**
     * Sentinelle du maillon zéro. 64 zéros hex = même largeur qu'un SHA-256,
     * ce qui préserve l'invariant « prev_hash est toujours un digest 64-hex ».
     * NB : la colonne `audit_logs.prev_hash` porte encore un DEFAULT SQL
     * 'GENESIS' hérité du schéma initial ; il est inatteignable, ce service
     * fournit toujours prev_hash explicitement.
     */
    public const GENESIS_PREV_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    private string $secret;

    public function __construct()
    {
        $this->secret = (string) env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me');
    }

    /** @param  array<string,mixed>  $row */
    public function record(array $row): int
    {
        return (int) DB::transaction(function () use ($row) {
            // Sérialise les écritures de la chaîne : deux inserts concurrents
            // liraient le même maillon de tête et produiraient deux lignes
            // pointant sur le même prev_hash — chaîne définitivement cassée.
            // Verrou de transaction : relâché au COMMIT/ROLLBACK.
            DB::statement("SELECT pg_advisory_xact_lock(hashtext('audit_logs.hash_chain'))");

            $prev = DB::selectOne('SELECT id, current_hash FROM audit_logs ORDER BY id DESC LIMIT 1');
            $prevHash = $prev->current_hash ?? self::GENESIS_PREV_HASH;

            // On construit la ligne TELLE QU'ELLE SERA STOCKÉE, puis on hashe
            // cette forme-là : c'est la seule que `verifyChain()` relira.
            $stored = [
                'workspace_id' => $row['workspace_id'] ?? null,
                'user_id' => $row['user_id'] ?? null,
                'event_type' => $row['method'] ?? 'unknown',
                'path' => $row['path'] ?? null,
                'status_code' => $row['status'] ?? null,
                'ip' => $row['ip'] ?? null,
                'user_agent' => $row['user_agent'] ?? null,
                'payload_hash' => $row['payload_hash'] ?? null,
            ];

            $currentHash = hash('sha256', $prevHash . $this->canonical($stored) . $this->secret);

            return DB::table('audit_logs')->insertGetId($stored + [
                'prev_hash' => $prevHash,
                'current_hash' => $currentHash,
                'created_at' => now(),
            ]);
        });
    }

    public function verifyChain(?int $maxRows = null): bool
    {
        $query = DB::table('audit_logs')->orderBy('id');
        if ($maxRows !== null) {
            $query->limit($maxRows);
        }

        $prevHash = self::GENESIS_PREV_HASH;
        foreach ($query->cursor() as $row) {
            // Le maillon déclaré par la ligne doit correspondre au maillon réel.
            if (! hash_equals($prevHash, (string) $row->prev_hash)) {
                return false;
            }

            $canonical = $this->canonical((array) $row);
            $expected = hash('sha256', $prevHash . $canonical . $this->secret);
            if (! hash_equals($expected, (string) $row->current_hash)) {
                return false;
            }
            $prevHash = (string) $row->current_hash;
        }

        return true;
    }

    /**
     * Représentation canonique STABLE d'une ligne d'audit.
     *
     * Accepte indifféremment la forme « colonnes » (event_type, status_code) et
     * la forme « appelant » (method, status). Les valeurs sont normalisées en
     * type PHP fixe : Postgres peut rendre un SMALLINT en chaîne selon le
     * driver, et json_encode(200) !== json_encode("200").
     *
     * @param  array<string,mixed>  $row
     */
    private function canonical(array $row): string
    {
        $payload = [
            'workspace_id' => self::normalizeId($row['workspace_id'] ?? null),
            'user_id' => self::normalizeId($row['user_id'] ?? null),
            'method' => self::normalizeText($row['event_type'] ?? $row['method'] ?? null),
            'path' => self::normalizeText($row['path'] ?? null),
            'status' => self::normalizeInt($row['status_code'] ?? $row['status'] ?? null),
            'ip' => self::normalizeText($row['ip'] ?? null),
            'payload_hash' => self::normalizeText($row['payload_hash'] ?? null),
        ];
        ksort($payload);

        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** UUID : Postgres restitue toujours en minuscules, on aligne l'écriture. */
    private static function normalizeId(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }

        return strtolower((string) $v);
    }

    private static function normalizeText(mixed $v): ?string
    {
        return $v === null ? null : (string) $v;
    }

    private static function normalizeInt(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }
}
