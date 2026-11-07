<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K13 — contrôles de listes noires (IP et domaines d'envoi).
 * ADDITIF SEULEMENT, AJOUT SEUL (un trigger refuse UPDATE/DELETE). `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_controles_listes (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                type         TEXT        NOT NULL CHECK (type IN ('ip', 'domaine')),
                ressource    TEXT        NOT NULL,
                statut       TEXT        NOT NULL CHECK (statut IN ('non_liste', 'liste', 'non_fiable')),
                zone         TEXT        NOT NULL,
                reponse      TEXT        NULL,
                pause        BOOLEAN     NOT NULL DEFAULT false,
                pause_le     TIMESTAMPTZ NULL,
                controle_le  TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL,
        );
        DB::statement('CREATE INDEX IF NOT EXISTS envoi_controles_listes_ressource ON envoi_controles_listes (workspace_id, type, ressource, id DESC)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_controles_listes_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_controles_listes : ajout seulement (UPDATE/DELETE refusés)';
            END $f$ LANGUAGE plpgsql
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_controles_listes_ajout_seul ON envoi_controles_listes');
        DB::statement('CREATE TRIGGER envoi_controles_listes_ajout_seul BEFORE UPDATE OR DELETE ON envoi_controles_listes FOR EACH ROW EXECUTE FUNCTION envoi_controles_listes_ajout_seul()');
        DB::statement('ALTER TABLE envoi_controles_listes ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_controles_listes FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_controles_listes_workspace_isolation ON envoi_controles_listes');
        DB::statement("CREATE POLICY envoi_controles_listes_workspace_isolation ON envoi_controles_listes FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE envoi_controles_listes IS 'Envoi K13 : controles de listes noires, ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.envoi_controles_listes TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_controles_listes_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les contrôles sont conservés. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_controles_listes IS 'Envoi K13 : table conservee au retour arriere.'");
    }
};
