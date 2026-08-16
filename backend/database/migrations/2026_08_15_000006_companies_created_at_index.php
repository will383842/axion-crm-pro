<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index pour le filtre par DATE D'ENTRÉE des fiches.
 *
 * MESURE avant index (EXPLAIN ANALYZE en production, 4,29 M de lignes) :
 * 3 487 ms pour compter les fiches des 7 derniers jours — `Parallel Seq Scan`
 * sur la table entière pour en trouver 150.
 *
 * Un filtre par date sans cet index serait PIRE que son absence : l'écran
 * paraîtrait cassé à chaque usage, et on chercherait la cause ailleurs. C'est
 * exactement le défaut mesuré à 6 383 ms sur la vue froide (#66), qu'on ne
 * rejoue pas.
 *
 * Partiel sur `deleted_at IS NULL` : la liste ne montre jamais de corbeille.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'idx_companies_ws_created_at';

    public function up(): void
    {
        $invalide = DB::select(
            'SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname = ? AND NOT i.indisvalid',
            [self::INDEX],
        );

        foreach ($invalide as $row) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $row->name);
        }

        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . '
            ON companies (workspace_id, created_at DESC)
            WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::INDEX);
    }
};
