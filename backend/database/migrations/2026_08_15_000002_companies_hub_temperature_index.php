<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Correctif de latence du hub de contacts, vue « Prospection (base froide) ».
 *
 * MESURÉ en production avant ce correctif (EXPLAIN ANALYZE, 2026-08-15) :
 * 6 383 ms pour ramener 51 lignes. Le plan lisait
 * `idx_companies_workspace_lifecycle_stage` — qui sait trouver les 4,29 M de
 * fiches « nouveau », mais ne dit RIEN de leur ordre — puis triait le résultat
 * entier : `Sort Method: external merge  Disk: 36392kB`, trois fois (deux
 * workers parallèles + le leader). Un tri sur disque de 4,29 M de lignes pour
 * en afficher 50, à chaque ouverture de l'onglet.
 *
 * L'index ci-dessous porte l'ORDRE de la liste (`updated_at DESC, id DESC`,
 * exactement celui du contrôleur — `id` départage, sans quoi la pagination par
 * curseur peut montrer deux fois la même fiche). Le tri disparaît : PostgreSQL
 * lit l'index dans l'ordre et s'arrête après 51 lignes.
 *
 * Partiel sur `deleted_at IS NULL` : la liste ne montre jamais de corbeille,
 * donc les lignes supprimées n'ont pas à peser dans l'index.
 *
 * Additif et réversible : aucune donnée touchée, `down()` rend l'état exact
 * d'avant.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const INDEX = 'idx_companies_ws_stage_updated_id';

    private const SQL = 'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::INDEX . '
        ON companies (workspace_id, lifecycle_stage, updated_at DESC, id DESC)
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
