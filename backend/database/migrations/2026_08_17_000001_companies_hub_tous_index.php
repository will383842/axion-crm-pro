<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correctif de latence du hub de contacts, vue « Tout » (SANS filtre d'étape).
 *
 * La migration `2026_08_15_000002` a posé
 * `(workspace_id, lifecycle_stage, updated_at DESC, id DESC)` et réglé la vue
 * « Prospection (base froide) », qui filtre sur l'étape. **La vue « Tout » n'en
 * profite pas** : sans prédicat sur `lifecycle_stage`, la colonne de tête
 * suivante de l'index ne peut pas être sautée, et PostgreSQL retombe sur un
 * balayage complet suivi d'un tri.
 *
 * MESURÉ le 2026-08-17, pendant l'E2E n°2 (critère §F.6 : « liste 100 000+
 * fluide, p95 < 500 ms ») :
 *
 *   LOCAL, 100 004 fiches — SELECT … WHERE workspace_id = ? AND deleted_at IS NULL
 *                           ORDER BY updated_at DESC, id DESC LIMIT 50
 *     sans filtre d'étape : Parallel Seq Scan + top-N heapsort → 344,7 ms
 *     avec filtre d'étape : Index Scan (index du 15/08)        →   0,32 ms
 *
 *   PRODUCTION, ~4,3 M fiches — même requête, EXPLAIN (sans ANALYZE, donc non
 *   exécutée sur la base de prod) :
 *     Parallel Seq Scan sur companies + Sort  (coût 580 351, 1 791 400 lignes
 *     estimées par worker)
 *
 * Le balayage croît linéairement avec le stock : ce qui coûte 345 ms sur
 * 100 000 fiches en coûte plusieurs secondes sur 4,3 M. La vue « Tout » est
 * l'entrée par défaut du hub.
 *
 * L'index ci-dessous est le FRÈRE de celui du 15/08, sans `lifecycle_stage` :
 * il porte l'ordre exact de la liste (`updated_at DESC, id DESC` — `id`
 * départage, sans quoi la pagination peut montrer deux fois la même fiche).
 *
 * Partiel sur `deleted_at IS NULL` : la liste ne montre jamais de corbeille.
 *
 * Additif et réversible : aucune donnée touchée, `down()` rend l'état d'avant.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'idx_companies_ws_updated_id';

    private const SQL = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . '
        ON companies (workspace_id, updated_at DESC, id DESC)
        WHERE deleted_at IS NULL';

    public function up(): void
    {
        // Un CONCURRENTLY interrompu laisse un index `indisvalid = false` que
        // `IF NOT EXISTS` tient pourtant pour existant : sans ce ménage, une
        // migration rejouée « réussirait » en laissant un index inutilisable.
        $invalid = DB::select(
            'SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname = ?
                AND NOT i.indisvalid',
            [self::INDEX],
        );

        foreach ($invalid as $row) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $row->name);
        }

        DB::statement(self::SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
    }
};
