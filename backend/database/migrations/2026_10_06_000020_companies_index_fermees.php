<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL des entreprises FERMÉES selon l'INSEE, au service du masquage
 * par défaut de la console (décision du propriétaire, 04/10/2026 —
 * `App\Support\EntreprisesFermees`).
 *
 * ── Pourquoi ──────────────────────────────────────────────────────────────
 * Les totaux des écrans (liste, accueil, indicateurs) vivent de parcours
 * d'index seul (`idx_companies_ws_counts`, `idx_companies_ws_enriched_at`…,
 * `Heap Fetches: 0`). Y ajouter `insee_ferme_le IS NULL` les obligerait à
 * relire le tas (≈ 9 Go) fiche par fiche : la colonne n'est dans aucun de ces
 * index. Ils comptent donc PAR DIFFÉRENCE — toutes (plan inchangé) moins les
 * fermées — et c'est ce second comptage que cet index sert, comme la vue
 * « fermées seules » (`fermees=seules`, triée par score).
 *
 * Prédicat `insee_ferme_le IS NOT NULL` SEUL (sans `deleted_at`) : il sert
 * aussi la répartition par taille de l'accueil, qui ne filtre pas la
 * corbeille. Clé `(workspace_id, quality_score DESC)` : l'ordre par défaut de
 * la liste. `quality_score` est déjà indexé (`idx_companies_workspace_score`)
 * et `insee_ferme_le` ne change qu'à une fermeture ou une réouverture : aucune
 * mise à jour aujourd'hui « HOT » ne cesse de l'être.
 *
 * ── Taille estimée ────────────────────────────────────────────────────────
 * Une entrée par fiche fermée (quelques milliers à quelques dizaines de
 * milliers sur ≈ 4,4 M) : `uuid` 16 + `smallint`, ~32 octets par entrée —
 * de l'ordre du mégaoctet.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée (verrou SHARE
 * UPDATE EXCLUSIVE seulement), deux passages sur la table. `lock_timeout`
 * borne l'attente du verrou initial. Un index laissé INVALIDE par une
 * construction interrompue est retiré puis reconstruit (patron de
 * `2026_10_04_000020`). Nom vérifié libre dans le dépôt le 2026-10-04.
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_companies_ws_fermees';

    private const DEFINITION = 'companies (workspace_id, quality_score DESC) WHERE insee_ferme_le IS NOT NULL';

    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'insee_ferme_le')) {
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
