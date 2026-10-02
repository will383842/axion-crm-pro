<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX `(workspace_id, enriched_at)` sur `companies`, au service de la carte
 * « Enrichies 24h » du tableau de bord.
 *
 * ── Pourquoi ──────────────────────────────────────────────────────────────
 * Le compteur lisait `updated_at` et affichait 1 671 720 « enrichies » le
 * 2026-10-02 (un recalcul massif du score qualité). Il lit désormais
 * `enriched_at >= now() - 1 jour`. Or `companies` (≈ 4,35 M de lignes) n'avait
 * AUCUN index utilisable sur `enriched_at` — seulement des index partiels
 * `WHERE enriched_at IS NULL`, inutiles pour cette requête. Sans celui-ci, le
 * comptage relirait toute la table.
 *
 * Index PARTIEL (`enriched_at IS NOT NULL AND deleted_at IS NULL`) : il ne
 * porte que les fiches enrichies et vivantes, exactement ce que compte le
 * tableau de bord (`DashboardController::compter` ajoute `deleted_at IS NULL`).
 * La requête répète `enriched_at IS NOT NULL` pour que le planificateur
 * reconnaisse l'index sans avoir à le déduire.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * Construit `CONCURRENTLY` (hors transaction, d'où `$withinTransaction =
 * false`) : aucune écriture n'est bloquée pendant la construction, seul un
 * verrou SHARE UPDATE EXCLUSIVE est pris (il n'exclut que les autres DDL et
 * VACUUM). Deux passages sur la table, quelques minutes au plus ; la commande
 * artisan n'est pas soumise au délai de 15 s des écrans. `lock_timeout` borne
 * l'attente du verrou initial. Un index laissé INVALIDE par une construction
 * interrompue est retiré puis reconstruit. Même patron que
 * `2026_10_01_000024_joignabilite_index`.
 *
 * PUREMENT ADDITIVE. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_companies_ws_enriched_at';

    private const DEFINITION = 'companies (workspace_id, enriched_at) WHERE enriched_at IS NOT NULL AND deleted_at IS NULL';

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
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
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
    }
};
