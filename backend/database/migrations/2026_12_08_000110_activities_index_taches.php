<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL de « Mes tâches » (lot G0, cahier docs/crm/lots/G1-G2-cahier.md § 2).
 *
 * `activities` est la timeline de tout l'espace ; une tâche y est une ligne `kind = 'task'`. La liste (lot G2-A) filtre
 * `workspace_id = :ws AND kind = 'task'` et trie `due_at NULLS LAST, id` : sans index, elle relirait toute la timeline.
 * ⚠️ Le prédicat `kind = 'task'` doit rester IDENTIQUE à la requête de G2-A.
 *
 * `CONCURRENTLY`, hors transaction. Un index INVALIDE de ce nom est retiré puis reconstruit.
 * PUREMENT ADDITIVE : aucune donnée touchée ; `down()` ne retire que l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_activities_taches';

    private const DEFINITION = "activities (workspace_id, due_at, id)
        WHERE kind = 'task'";

    /**
     * `CONCURRENTLY` est refusé DANS une transaction. En production la migration tourne hors transaction
     * (`$withinTransaction = false`) : construction sans blocage. Le filet NR-17 rejoue up()/down() dans la
     * transaction de test : il obtient alors la forme ordinaire (même index, même prédicat).
     */
    private static function concurremment(): string
    {
        return DB::transactionLevel() === 0 ? ' CONCURRENTLY' : '';
    }

    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
            $invalide = DB::selectOne(
                'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                [self::NOM],
            );
            if ($invalide !== null && (bool) $invalide->invalide) {
                DB::statement('DROP INDEX' . self::concurremment() . ' IF EXISTS ' . self::NOM);
            }
            DB::statement('CREATE INDEX' . self::concurremment() . ' IF NOT EXISTS ' . self::NOM . ' ON ' . self::DEFINITION);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX' . self::concurremment() . ' IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
