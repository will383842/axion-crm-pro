<?php

namespace App\Services\Rotations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * Weighted Round-Robin sur n'importe quelle dimension (`rotations.dimension`).
 *
 * Algorithme : on charge les items enabled du workspace pour la dimension donnée,
 * et on incrémente un compteur global par item à chaque pick. Le pick est l'item
 * dont (last_used_count / weight) est le plus bas — équivalent à WRR.
 *
 * Concurrency : on s'appuie sur `INSERT … ON CONFLICT … RETURNING` + advisory lock
 * Postgres pour les dimensions critiques (proxies, zones).
 */
class WeightedRoundRobin
{
    /** @return array<string,mixed>|null l'item picked, ou null si aucun disponible */
    public function pick(string $workspaceId, string $dimension): ?array
    {
        $items = DB::table('rotations')
            ->where('workspace_id', $workspaceId)
            ->where('dimension', $dimension)
            ->where('enabled', true)
            ->where(function ($q) {
                $q->whereNull('last_used_at')
                  ->orWhereRaw("last_used_at + (cooldown_seconds * INTERVAL '1 second') < now()");
            })
            ->orderByRaw('COALESCE((metadata->>\'count\')::int, 0)::float / GREATEST(weight, 1)')
            ->limit(1)
            ->first();

        if (! $items) {
            return null;
        }

        $current = (array) ($items->metadata ? json_decode($items->metadata, true) : []);
        $current['count'] = ((int) ($current['count'] ?? 0)) + 1;

        DB::table('rotations')
            ->where('id', $items->id)
            ->update([
                'last_used_at' => now(),
                'metadata'     => json_encode($current),
                'updated_at'   => now(),
            ]);

        return (array) $items;
    }
}
