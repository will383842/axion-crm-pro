<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL de la corbeille des contacts (lot G0, cahier docs/crm/lots/G1-G2-cahier.md § 2).
 *
 * La corbeille de la console (lot G1-B) lit les contacts retirés PAR LA CONSOLE :
 *
 *     workspace_id = :ws AND deleted_at IS NOT NULL AND metadata ? 'retire_console_le'
 *     ORDER BY deleted_at DESC, id DESC LIMIT 200
 *
 * Un index partiel au prédicat identique ne porte que ces lignes (quelques-unes) et sert aussi le tri.
 * ⚠️ Le prédicat doit rester IDENTIQUE, mot pour mot, à la requête de G1-B : réécrit autrement, l'index ne serait
 * plus reconnu, sans aucune erreur (leçon de 2026_10_03_000040).
 *
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée. Un index INVALIDE de ce nom est retiré puis reconstruit.
 * PUREMENT ADDITIVE : aucune donnée touchée ; `down()` ne retire que l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_contacts_corbeille_console';

    private const DEFINITION = "contacts (workspace_id, deleted_at DESC, id DESC)
        WHERE deleted_at IS NOT NULL
          AND metadata ?? 'retire_console_le'";

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
        if (! Schema::hasTable('contacts')) {
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
