<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Index de la liste « Contacts » de la console (1,3 M de lignes).
 *
 * L'endpoint `GET /contacts` renvoyait une liste vide EN DUR : il n'a donc
 * jamais eu besoin d'index. En le branchant réellement, on hérite du problème
 * mesuré le même jour sur la liste entreprises — un tri sur disque de la table
 * entière pour afficher 50 lignes (6 383 ms). Ces index évitent de rejouer ce
 * défaut plutôt que de le découvrir en production.
 *
 * Trois index, un par usage RÉEL de l'écran (aucun « au cas où ») :
 *   1. liste par défaut          → (workspace_id, id DESC)
 *   2. filtre statut e-mail      → (workspace_id, email_status, id DESC)
 *   3. recherche par nom         → (workspace_id, lower(last_name))
 *
 * Le n°3 est FONCTIONNEL et en `text_pattern_ops` : c'est ce qui rend
 * `lower(last_name) LIKE 'dup%'` indexable. Sans `text_pattern_ops`, un index
 * B-tree ordinaire ne sert PAS les recherches par préfixe hors collation C.
 *
 * Tous partiels sur `deleted_at IS NULL` : la liste ne montre jamais la
 * corbeille, les lignes supprimées n'ont donc pas à peser dans l'index.
 *
 * Additif et réversible : aucune donnée touchée.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * @var array<string, string>
     */
    private array $indexes = [
        'idx_contacts_ws_id_desc' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_contacts_ws_id_desc
            ON contacts (workspace_id, id DESC) WHERE deleted_at IS NULL',

        'idx_contacts_ws_email_status_id' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_contacts_ws_email_status_id
            ON contacts (workspace_id, email_status, id DESC) WHERE deleted_at IS NULL',

        'idx_contacts_ws_lower_last_name' => 'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_contacts_ws_lower_last_name
            ON contacts (workspace_id, lower(last_name) text_pattern_ops) WHERE deleted_at IS NULL',
    ];

    public function up(): void
    {
        $this->dropInvalidLeftovers();

        foreach ($this->indexes as $sql) {
            DB::statement($sql);
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$name}");
        }
    }

    /**
     * Un CONCURRENTLY interrompu laisse un index `indisvalid = false` que
     * `IF NOT EXISTS` tient pourtant pour existant : sans ce ménage, la
     * migration rejouée « réussirait » en laissant un index inutilisable.
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
