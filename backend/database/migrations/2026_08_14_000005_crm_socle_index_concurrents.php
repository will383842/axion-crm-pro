<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Lot L1 — index des nouvelles colonnes sur les GROSSES tables.
 *
 * Migration séparée et HORS TRANSACTION : `CREATE INDEX CONCURRENTLY` ne peut
 * pas s'exécuter dans une transaction, et Laravel enveloppe les migrations
 * PostgreSQL dans une transaction par défaut.
 *
 * Pourquoi CONCURRENTLY : `companies` compte ~4,29 M lignes et `contacts`
 * ~1,3 M sur un CPX22 (2 vCPU, 3,7 Go). Un `CREATE INDEX` ordinaire prend un
 * verrou SHARE — il BLOQUE toutes les écritures le temps de la construction,
 * donc Horizon et les workers de collecte. En CONCURRENTLY, les écritures
 * continuent ; le prix est deux passes au lieu d'une.
 *
 * ⚠️ Conséquence de l'absence de transaction : si une création échoue, un index
 * INVALIDE peut subsister. La migration commence donc par nettoyer les index
 * invalides qu'elle aurait pu laisser, ce qui la rend rejouable.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * @var array<string, array{table: string, sql: string}>
     */
    private array $indexes = [
        'idx_companies_workspace_relation_type' => [
            'table' => 'companies',
            'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_companies_workspace_relation_type ON companies (workspace_id, relation_type)',
        ],
        'idx_companies_workspace_lifecycle_stage' => [
            'table' => 'companies',
            'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_companies_workspace_lifecycle_stage ON companies (workspace_id, lifecycle_stage)',
        ],
        'companies_workspace_external_ref_key' => [
            'table' => 'companies',
            'sql' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS companies_workspace_external_ref_key ON companies (workspace_id, external_ref) WHERE external_ref IS NOT NULL',
        ],
        'idx_contacts_workspace_person_key' => [
            'table' => 'contacts',
            'sql' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_contacts_workspace_person_key ON contacts (workspace_id, person_key) WHERE person_key IS NOT NULL',
        ],
        'contacts_workspace_external_ref_key' => [
            'table' => 'contacts',
            'sql' => 'CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS contacts_workspace_external_ref_key ON contacts (workspace_id, external_ref) WHERE external_ref IS NOT NULL',
        ],
    ];

    public function up(): void
    {
        $this->dropInvalidLeftovers();

        foreach ($this->indexes as $definition) {
            DB::statement($definition['sql']);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    /**
     * Un CONCURRENTLY interrompu laisse un index marqué `indisvalid = false`,
     * que `IF NOT EXISTS` considère pourtant comme existant : sans ce ménage,
     * la migration rejouée « réussirait » en laissant un index inutilisable.
     */
    private function dropInvalidLeftovers(): void
    {
        $names = array_keys($this->indexes);
        $placeholders = implode(', ', array_fill(0, count($names), '?'));

        $invalid = DB::select(
            "SELECT c.relname AS name
               FROM pg_class c
               JOIN pg_index i ON i.indexrelid = c.oid
              WHERE c.relname IN ({$placeholders})
                AND NOT i.indisvalid",
            $names,
        );

        foreach ($invalid as $row) {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . $row->name);
        }
    }
};
