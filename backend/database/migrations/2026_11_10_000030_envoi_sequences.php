<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 — séquences (1 à 3 étapes) et leurs étapes.
 * ADDITIF SEULEMENT. workspace_id + RLS FORCE. `down()` inerte (rien n'est supprimé).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_sequences (
                id            UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id  UUID        NOT NULL REFERENCES workspaces(id),
                nom           TEXT        NOT NULL,
                fenetre_debut SMALLINT    NOT NULL DEFAULT 9  CHECK (fenetre_debut BETWEEN 0 AND 23),
                fenetre_fin   SMALLINT    NOT NULL DEFAULT 17 CHECK (fenetre_fin BETWEEN 1 AND 24),
                cree_le       TIMESTAMPTZ NOT NULL DEFAULT now(),
                CHECK (fenetre_debut < fenetre_fin),
                UNIQUE (workspace_id, id)
            )
            SQL,
        );
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_sequence_etapes (
                id                 UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id       UUID        NOT NULL REFERENCES workspaces(id),
                sequence_id        UUID        NOT NULL,
                rang               SMALLINT    NOT NULL CHECK (rang BETWEEN 1 AND 3),
                delai_jours_ouvres SMALLINT    NOT NULL DEFAULT 0 CHECK (delai_jours_ouvres BETWEEN 0 AND 60),
                sujet              TEXT        NOT NULL,
                corps              TEXT        NOT NULL,
                cree_le            TIMESTAMPTZ NOT NULL DEFAULT now(),
                FOREIGN KEY (workspace_id, sequence_id) REFERENCES envoi_sequences (workspace_id, id),
                UNIQUE (sequence_id, rang)
            )
            SQL,
        );
        foreach (['envoi_sequences', 'envoi_sequence_etapes'] as $t) {
            self::securiser($t);
        }
    }

    private static function securiser(string $table): void
    {
        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON {$table}");
        DB::statement("CREATE POLICY {$table}_workspace_isolation ON {$table} FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE {$table} IS 'Envoi P0 L1 : sequenceur. Ajout seul, aucune adresse en clair.'");
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement("GRANT SELECT, INSERT, UPDATE ON public.{$table} TO {$role}");
        }
    }

    /** Retour arrière INERTE : les séquences sont conservées. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_sequences IS 'Envoi P0 L1 : table conservee au retour arriere.'");
        DB::statement("COMMENT ON TABLE envoi_sequence_etapes IS 'Envoi P0 L1 : table conservee au retour arriere.'");
    }
};
