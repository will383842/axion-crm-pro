<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index B-tree (workspace_id, denomination_normalized) sur `companies`
 * (chantier durcissement audit 2026-07-09).
 *
 * Problème : `media:link-to-companies` matche les médias sur le nom exact
 * normalisé de l'éditeur (`denomination_normalized = ?`). Le seul index existant
 * sur cette colonne est un GIN trigram (`idx_companies_denomination_trgm`),
 * inutile pour une égalité stricte → Postgres tombe en seq scan sur les 4,3M
 * lignes companies à chaque rattachement. Un B-tree (workspace_id, denom) rend
 * ce match index-only.
 *
 * ⚠️ CREATE INDEX CONCURRENTLY est INTERDIT dans une transaction. Laravel 11
 * wrappe chaque migration dans une transaction par défaut → on la désactive via
 * `$withinTransaction = false` pour ce fichier.
 */
return new class extends Migration
{
    /**
     * CONCURRENTLY ne peut pas s'exécuter dans une transaction (Postgres) —
     * Laravel doit donc lancer cette migration hors transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_companies_denom_btree ON companies (workspace_id, denomination_normalized)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_companies_denom_btree');
    }
};
