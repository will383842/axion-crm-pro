<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL des fiches à rafraîchir EN PRIORITÉ par la mise à jour
 * mensuelle INSEE (lot N8, 03/10/2026), au service de
 * `App\Crm\Insee\MiseAJourMensuelle::fichesPrioritaires()`.
 *
 * ── Pourquoi ──────────────────────────────────────────────────────────────
 * La passe prioritaire lit les fiches de PROVENANCE TIERS (fédérations,
 * organisateurs, sites, annuaires… : `discovery_source` autre que `insee`)
 * qui portent un SIREN et n'ont pas été confrontées à Sirene depuis la date
 * du passage — `insee_verifiee_le` NULL (« non vérifiée INSEE ») d'abord.
 * Sans index, chaque passage relirait les 4,35 M de fiches (9,3 Go) pour en
 * trouver quelques milliers.
 *
 * L'index ne porte QUE ces fiches : le prédicat reprend la condition de la
 * requête MOT POUR MOT (le planificateur ne reconnaît que celle-là), sous la
 * sécurité par espace (`axion_app`) comme sous le propriétaire.
 *
 * ⚠️ `insee_verifiee_le` n'est VOLONTAIREMENT ni dans la clé ni dans le
 * prédicat : une colonne indexée interdit les mises à jour HOT de TOUTE la
 * table — chaque horodatage réécrirait la trentaine d'index de `companies`.
 * Le tri « jamais confrontées d'abord » se fait sur le petit ensemble rendu
 * par l'index.
 * `MiseAJourMensuelle::PREDICAT_PRIORITE` est la source unique ; la garde
 * `InseeMiseAJourMensuelleTest` lit le plan et rougit si elles divergent.
 *
 * ── Taille estimée ────────────────────────────────────────────────────────
 * Une entrée par fiche tiers à SIREN (quelques dizaines de milliers au plus) :
 * clé `uuid` 16 + `bigint` 8, ~36 octets par entrée avec l'en-tête et le
 * pointeur — moins de 4 Mo pour 100 000 fiches.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée (verrou SHARE
 * UPDATE EXCLUSIVE seulement), deux passages sur la table. `lock_timeout`
 * borne l'attente du verrou initial. Un index laissé INVALIDE par une
 * construction interrompue est retiré puis reconstruit (patron de
 * `2026_10_03_000060`). Nom vérifié libre dans le dépôt le 2026-10-03.
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_companies_insee_priorite';

    private const DEFINITION = "companies (workspace_id, id)
        WHERE siren IS NOT NULL
          AND discovery_source IS DISTINCT FROM 'insee'
          AND deleted_at IS NULL";

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
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
