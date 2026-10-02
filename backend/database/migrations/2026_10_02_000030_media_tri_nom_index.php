<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MÉDIAS — l'index qui sert le tri par nom « lisible » (2026-10-02).
 *
 * La liste des médias est triée par défaut sur le nom SANS ses signes de tête
 * (`App\Support\TriNomMedia::EXPRESSION`) : « + Plus » se range à P, plus en
 * tête de liste. Cet index porte EXACTEMENT cette expression, précédée de
 * l'espace de travail (toute lecture de `MediaController` filtre dessus) ;
 * la liste paginée (100 lignes) se lit alors dans l'ordre de l'index au lieu
 * de trier toute la table.
 *
 * Coût : un index seul, aucune réécriture de table (≈ 56 000 lignes).
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée. Un index laissé
 * INVALIDE par une construction interrompue est retiré puis reconstruit
 * (patron de `2026_10_01_000041`). Nom vérifié libre le 2026-10-02.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public const NOM = 'idx_media_tri_nom';

    public const DEFINITION = "media (workspace_id, (regexp_replace(name, '^[^[:alnum:]]+', ''))) WHERE deleted_at IS NULL";

    public function up(): void
    {
        if (! Schema::hasTable('media')) {
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
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::NOM . ' ON ' . self::DEFINITION);
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
