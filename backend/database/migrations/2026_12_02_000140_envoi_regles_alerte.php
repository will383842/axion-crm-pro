<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R14 — règles d'alerte (type × canaux), historisées : une ligne par enregistrement, la dernière fait foi.
 * AJOUT SEUL : UPDATE, DELETE et TRUNCATE refusés par déclencheur ; RLS par espace. Aucune adresse stockée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_regles_alerte (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                condition    TEXT        NOT NULL CHECK (char_length(condition) BETWEEN 1 AND 60),
                nom          TEXT        NOT NULL CHECK (char_length(nom) BETWEEN 1 AND 120),
                seuil        NUMERIC     NULL,
                canaux       TEXT        NOT NULL CHECK (canaux ~ '^(console|email)(,(console|email))?$' OR canaux = ''),
                actif        BOOLEAN     NOT NULL,
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_regles_alerte_ws_cond ON envoi_regles_alerte (workspace_id, condition, id DESC);
            ALTER TABLE envoi_regles_alerte ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_regles_alerte FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_regles_alerte_workspace_isolation ON envoi_regles_alerte;
            CREATE POLICY envoi_regles_alerte_workspace_isolation ON envoi_regles_alerte FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_regles_alerte_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_regles_alerte : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_regles_alerte_ajout_seul ON envoi_regles_alerte;
            CREATE TRIGGER envoi_regles_alerte_ajout_seul BEFORE UPDATE OR DELETE ON envoi_regles_alerte
                FOR EACH ROW EXECUTE FUNCTION envoi_regles_alerte_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_regles_alerte_pas_de_truncate ON envoi_regles_alerte;
            CREATE TRIGGER envoi_regles_alerte_pas_de_truncate BEFORE TRUNCATE ON envoi_regles_alerte
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_regles_alerte_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_regles_alerte FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_regles_alerte TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_regles_alerte_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des règles est conservé. */
    public function down(): void {}
};
