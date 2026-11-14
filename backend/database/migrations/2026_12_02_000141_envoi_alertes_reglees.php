<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R14 — alertes marquées « réglées » (alerte, auteur, date) ; une alerte réglée reste dans l historique.
 * AJOUT SEUL : UPDATE, DELETE et TRUNCATE refusés par déclencheur ; RLS par espace. Aucune adresse stockée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_alertes_reglees (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                alerte_id    TEXT        NOT NULL CHECK (char_length(alerte_id) BETWEEN 1 AND 80),
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, alerte_id)
            );
            ALTER TABLE envoi_alertes_reglees ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_alertes_reglees FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_alertes_reglees_workspace_isolation ON envoi_alertes_reglees;
            CREATE POLICY envoi_alertes_reglees_workspace_isolation ON envoi_alertes_reglees FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_alertes_reglees_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_alertes_reglees : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_alertes_reglees_ajout_seul ON envoi_alertes_reglees;
            CREATE TRIGGER envoi_alertes_reglees_ajout_seul BEFORE UPDATE OR DELETE ON envoi_alertes_reglees
                FOR EACH ROW EXECUTE FUNCTION envoi_alertes_reglees_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_alertes_reglees_pas_de_truncate ON envoi_alertes_reglees;
            CREATE TRIGGER envoi_alertes_reglees_pas_de_truncate BEFORE TRUNCATE ON envoi_alertes_reglees
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_alertes_reglees_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_alertes_reglees FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_alertes_reglees TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_alertes_reglees_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique est conservé. */
    public function down(): void {}
};
