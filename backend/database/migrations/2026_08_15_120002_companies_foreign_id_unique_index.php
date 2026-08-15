<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index unique de DÉDUP des fiches sans SIREN (suite de `…120001`).
 *
 * `UNIQUE (workspace_id, siren)` ne protège plus rien dès que `siren` est
 * NULL : PostgreSQL considère deux NULL comme distincts, donc 468 entités
 * roumaines pourraient être insérées deux fois sans que rien ne rougisse.
 * Le pendant étranger de cette unicité est un index PARTIEL sur
 * (workspace_id, country_code, foreign_id).
 *
 * Migration SÉPARÉE et HORS TRANSACTION : `CREATE INDEX CONCURRENTLY` ne peut
 * pas tourner dans une transaction, et `companies` compte ~4,29 M de lignes —
 * un index classique poserait un ACCESS EXCLUSIVE et gèlerait Horizon et les
 * workers de collecte le temps de la construction.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        DB::statement(
            'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS companies_workspace_foreign_id_unique '
            . 'ON companies (workspace_id, country_code, foreign_id) '
            . 'WHERE foreign_id IS NOT NULL'
        );

        // Recherche par nature (« tous les organismes de Roumanie ») : sans
        // index, la console scannerait 4,29 M de lignes pour en ramener 468.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_companies_workspace_country_nature '
            . 'ON companies (workspace_id, country_code, entity_nature) '
            . "WHERE country_code <> 'FR'"
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS companies_workspace_foreign_id_unique');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_companies_workspace_country_nature');
    }
};
