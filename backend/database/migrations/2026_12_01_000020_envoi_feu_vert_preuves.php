<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** Envoi L8 (F04) — preuves des points MANUELS du feu vert. AJOUT SEUL ; UPDATE/DELETE/TRUNCATE refusés ; RLS par espace. */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_feu_vert_preuves (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                code         TEXT        NOT NULL CHECK (char_length(code) BETWEEN 1 AND 64),
                preuve       TEXT        NOT NULL CHECK (char_length(preuve) BETWEEN 1 AND 500),
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_feu_vert_preuves_ws_code ON envoi_feu_vert_preuves (workspace_id, code, id DESC);
            ALTER TABLE envoi_feu_vert_preuves ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_feu_vert_preuves FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_feu_vert_preuves_workspace_isolation ON envoi_feu_vert_preuves;
            CREATE POLICY envoi_feu_vert_preuves_workspace_isolation ON envoi_feu_vert_preuves FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_feu_vert_preuves_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_feu_vert_preuves : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_feu_vert_preuves_ajout_seul ON envoi_feu_vert_preuves;
            CREATE TRIGGER envoi_feu_vert_preuves_ajout_seul BEFORE UPDATE OR DELETE ON envoi_feu_vert_preuves
                FOR EACH ROW EXECUTE FUNCTION envoi_feu_vert_preuves_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_feu_vert_preuves_pas_de_truncate ON envoi_feu_vert_preuves;
            CREATE TRIGGER envoi_feu_vert_preuves_pas_de_truncate BEFORE TRUNCATE ON envoi_feu_vert_preuves
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_feu_vert_preuves_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_feu_vert_preuves FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_feu_vert_preuves TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_feu_vert_preuves_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les preuves sont conservées. */
    public function down(): void {}
};
