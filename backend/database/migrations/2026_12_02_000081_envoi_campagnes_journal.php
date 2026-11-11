<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R08 — journal des transitions de campagne, AJOUT SEUL (UPDATE/DELETE/TRUNCATE refusés ; droits SELECT + INSERT).
 * La clé d'idempotence est unique par espace : rejouer une transition n'écrit pas deux fois. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_campagnes_journal (
                id              BIGSERIAL   PRIMARY KEY,
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                campagne_id     UUID        NOT NULL,
                transition      TEXT        NOT NULL CHECK (transition ~ '^[a-z_]+>[a-z_]+$'),
                auteur          TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                motif           TEXT        NOT NULL CHECK (char_length(motif) BETWEEN 1 AND 500),
                cle_idempotence TEXT        NOT NULL CHECK (char_length(cle_idempotence) BETWEEN 8 AND 100),
                cree_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_campagnes_journal_cle_unique UNIQUE (workspace_id, cle_idempotence),
                CONSTRAINT envoi_campagnes_journal_campagne FOREIGN KEY (campagne_id, workspace_id) REFERENCES envoi_campagnes (id, workspace_id)
            );
            CREATE INDEX IF NOT EXISTS envoi_campagnes_journal_campagne_idx ON envoi_campagnes_journal (campagne_id, id);
            ALTER TABLE envoi_campagnes_journal ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_campagnes_journal FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_campagnes_journal_workspace_isolation ON envoi_campagnes_journal;
            CREATE POLICY envoi_campagnes_journal_workspace_isolation ON envoi_campagnes_journal FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_campagnes_journal_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_campagnes_journal : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_journal_ajout_seul ON envoi_campagnes_journal;
            CREATE TRIGGER envoi_campagnes_journal_ajout_seul BEFORE UPDATE OR DELETE ON envoi_campagnes_journal
                FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_journal_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_campagnes_journal_pas_de_truncate ON envoi_campagnes_journal;
            CREATE TRIGGER envoi_campagnes_journal_pas_de_truncate BEFORE TRUNCATE ON envoi_campagnes_journal
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_campagnes_journal_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_campagnes_journal FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_campagnes_journal TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_campagnes_journal_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : le journal est conservé. */
    public function down(): void {}
};
