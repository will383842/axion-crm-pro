<?php

use App\Support\TriNomMedia;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MÉDIAS — la clé et l'index du tri par nom « lisible » (2026-10-02).
 *
 * `cle_tri_nom(texte)` : sans accents, sans signes de tête, en minuscules
 * (détail et raisons dans `App\Support\TriNomMedia`). La fonction est déclarée
 * IMMUTABLE pour être indexable — `unaccent` ne l'est pas à cause de son
 * dictionnaire configurable ; on lui passe donc le dictionnaire EXPLICITEMENT
 * (`public.unaccent`), comme le fait déjà `normalize_name`. Tout est qualifié
 * et le `search_path` est fixé : une restauration par `pg_dump` (search_path
 * vide) la résout (cf. `2026_08_16_200000_fixer_search_path_des_fonctions`).
 *
 * L'index `idx_media_tri_nom` est construit depuis `TriNomMedia::expression()`,
 * la même source que l'ORDER BY, précédé de l'espace de travail (toute lecture
 * de `MediaController` filtre dessus). La page de 100 lignes se lit alors dans
 * l'ordre de l'index au lieu de trier la table.
 *
 * Coût : une fonction et un index, aucune réécriture de table (≈ 56 000
 * lignes). `CONCURRENTLY`, hors transaction : aucune écriture bloquée. Un index
 * laissé INVALIDE par une construction interrompue est retiré puis reconstruit
 * (patron de `2026_10_01_000024` / `2026_10_01_000041`). Noms vérifiés libres
 * le 2026-10-02.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public const NOM = 'idx_media_tri_nom';

    public function up(): void
    {
        if (! Schema::hasTable('media')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.cle_tri_nom(input TEXT) RETURNS TEXT
            LANGUAGE sql IMMUTABLE PARALLEL SAFE
            SET search_path = public, pg_catalog
            AS $$
              SELECT lower(regexp_replace(
                public.unaccent('public.unaccent'::regdictionary, coalesce(input, '')),
                '^[^[:alnum:]]+', ''
              ))
            $$
        SQL);

        $definition = 'media (workspace_id, (' . TriNomMedia::expression('name') . ')) WHERE deleted_at IS NULL';

        DB::statement("SET lock_timeout = '30s'");
        try {
            $invalide = DB::selectOne(
                'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                [self::NOM],
            );
            if ($invalide !== null && (bool) $invalide->invalide) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::NOM . ' ON ' . $definition);
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
        DB::statement('DROP FUNCTION IF EXISTS public.cle_tri_nom(TEXT)');
    }
};
