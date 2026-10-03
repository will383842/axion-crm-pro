<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX DES OPPOSITIONS PAR EMPREINTE DE TÉLÉPHONE (N12, 03/10/2026).
 *
 * Sorti de `2026_10_03_000080_provenance_tiers` (relecture de #312) : même
 * partiel et vide, un `CREATE INDEX` ordinaire relit toute la table `opt_out`
 * sous verrou SHARE, et pendant ce temps l'ENREGISTREMENT DES OPPOSITIONS est
 * bloqué. Ici : `CONCURRENTLY`, hors transaction (`$withinTransaction =
 * false`), seul un verrou SHARE UPDATE EXCLUSIVE est pris — aucune écriture
 * bloquée. `lock_timeout` borne l'attente du verrou initial. Un index laissé
 * INVALIDE par une construction interrompue est retiré puis reconstruit
 * (patron de `2026_10_03_000060`).
 *
 * À mesurer après coup : `SELECT pg_size_pretty(pg_relation_size('opt_out'))`
 * (durée de la relecture) et
 * `SELECT pg_size_pretty(pg_relation_size('idx_opt_out_scope_phone_hash'))`
 * (vide tant que le canal Partners n'écrit rien).
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_opt_out_scope_phone_hash';

    public function up(): void
    {
        if (! Schema::hasColumn('opt_out', 'phone_hash')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
            $invalide = DB::selectOne(
                'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                [self::NOM],
            );
            if ($invalide !== null && (bool) $invalide->invalide) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
            }
            DB::statement(
                'CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::NOM
                . ' ON opt_out (scope, phone_hash) WHERE phone_hash IS NOT NULL',
            );
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
