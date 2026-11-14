<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-A (D6) — gestes de pause et de reprise d'une séquence. AJOUT SEUL : l'état d'une séquence est son DERNIER geste.
 * RLS forcée par espace, clé étrangère composite vers `envoi_sequences`, UPDATE/DELETE/TRUNCATE refusés, droits SELECT + INSERT.
 * `motif` est un texte libre : aucun `@` (aucune adresse dans la base d'envoi). down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_sequences_gestes (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                sequence_id  UUID        NOT NULL,
                geste        TEXT        NOT NULL CHECK (geste IN ('pause', 'reprise')),
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                motif        TEXT        NULL CHECK (motif IS NULL OR (char_length(motif) <= 500 AND position('@' IN motif) = 0)),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                FOREIGN KEY (workspace_id, sequence_id) REFERENCES envoi_sequences (workspace_id, id)
            );
            CREATE INDEX IF NOT EXISTS envoi_sequences_gestes_dernier ON envoi_sequences_gestes (workspace_id, sequence_id, cree_le DESC);
            ALTER TABLE envoi_sequences_gestes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_sequences_gestes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_sequences_gestes_workspace_isolation ON envoi_sequences_gestes;
            CREATE POLICY envoi_sequences_gestes_workspace_isolation ON envoi_sequences_gestes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_sequences_gestes_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_sequences_gestes : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_sequences_gestes_ajout_seul ON envoi_sequences_gestes;
            CREATE TRIGGER envoi_sequences_gestes_ajout_seul BEFORE UPDATE OR DELETE ON envoi_sequences_gestes
                FOR EACH ROW EXECUTE FUNCTION envoi_sequences_gestes_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_sequences_gestes_pas_de_truncate ON envoi_sequences_gestes;
            CREATE TRIGGER envoi_sequences_gestes_pas_de_truncate BEFORE TRUNCATE ON envoi_sequences_gestes
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_sequences_gestes_ajout_seul();
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_sequences_gestes FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_sequences_gestes TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des gestes est conservé. */
    public function down(): void {}
};
