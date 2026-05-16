<?php

namespace App\Services\Audit;

use Illuminate\Support\Facades\DB;

/**
 * Audit append-only avec chaîne cryptographique vérifiable.
 *   hash_n = sha256( hash_(n-1) || canonical_json(row_n) || secret )
 */
class AuditHashChain
{
    private string $secret;

    public function __construct()
    {
        $this->secret = (string) env('AUDIT_HASH_CHAIN_SECRET', 'dev-only-secret-change-me');
    }

    /** @param  array<string,mixed>  $row */
    public function record(array $row): int
    {
        return (int) DB::transaction(function () use ($row) {
            $prev = DB::selectOne('SELECT id, current_hash FROM audit_logs ORDER BY id DESC LIMIT 1');
            $prevHash = $prev->current_hash ?? str_repeat('0', 64);

            $canonical = $this->canonical($row);
            $currentHash = hash('sha256', $prevHash . $canonical . $this->secret);

            $id = DB::table('audit_logs')->insertGetId([
                'workspace_id' => $row['workspace_id'] ?? null,
                'user_id'      => $row['user_id'] ?? null,
                'event_type'   => $row['method'] ?? 'unknown',
                'path'         => $row['path'] ?? null,
                'status_code'  => $row['status'] ?? null,
                'ip'           => $row['ip'] ?? null,
                'user_agent'   => $row['user_agent'] ?? null,
                'payload_hash' => $row['payload_hash'] ?? null,
                'prev_hash'    => $prevHash,
                'current_hash' => $currentHash,
                'created_at'   => now(),
            ]);
            return $id;
        });
    }

    public function verifyChain(?int $maxRows = null): bool
    {
        $query = DB::table('audit_logs')->orderBy('id');
        if ($maxRows !== null) {
            $query->limit($maxRows);
        }

        $prevHash = str_repeat('0', 64);
        foreach ($query->cursor() as $row) {
            $canonical = $this->canonical((array) $row);
            $expected  = hash('sha256', $prevHash . $canonical . $this->secret);
            if (! hash_equals($expected, $row->current_hash)) {
                return false;
            }
            $prevHash = $row->current_hash;
        }
        return true;
    }

    /** @param  array<string,mixed>  $row */
    private function canonical(array $row): string
    {
        $payload = [
            'workspace_id' => $row['workspace_id'] ?? null,
            'user_id'      => $row['user_id'] ?? null,
            'method'       => $row['method'] ?? null,
            'path'         => $row['path'] ?? null,
            'status'       => $row['status'] ?? $row['status_code'] ?? null,
            'ip'           => $row['ip'] ?? null,
            'payload_hash' => $row['payload_hash'] ?? null,
        ];
        ksort($payload);
        return json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
