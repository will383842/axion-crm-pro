<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R06 — tentatives d'envoi par fournisseur : source réelle du frein « reports en hausse » (point h).
 * Une ligne par conclusion de réservation (clé d'idempotence = clé de la réservation, unique par espace).
 * AJOUT SEUL : UPDATE/DELETE/TRUNCATE refusés ; RLS par espace ; droits SELECT + INSERT. Aucune adresse stockée.
 * Au palier 0 rien ne part : la table reste vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_tentatives (
                id               BIGSERIAL   PRIMARY KEY,
                workspace_id     UUID        NOT NULL REFERENCES workspaces(id),
                fournisseur      TEXT        NOT NULL CHECK (fournisseur IN ('gmail', 'microsoft', 'autre')),
                issue            TEXT        NOT NULL CHECK (issue IN ('acceptee', 'reportee', 'refusee')),
                code_smtp        SMALLINT    NULL CHECK (code_smtp BETWEEN 200 AND 599),
                cle_idempotence  TEXT        NOT NULL CHECK (char_length(cle_idempotence) BETWEEN 1 AND 300),
                cree_le          TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_tentatives_cle_unique UNIQUE (workspace_id, cle_idempotence)
            );
            CREATE INDEX IF NOT EXISTS envoi_tentatives_ws_fournisseur_date ON envoi_tentatives (workspace_id, fournisseur, cree_le);
            ALTER TABLE envoi_tentatives ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_tentatives FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_tentatives_workspace_isolation ON envoi_tentatives;
            CREATE POLICY envoi_tentatives_workspace_isolation ON envoi_tentatives FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_tentatives_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_tentatives : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_tentatives_ajout_seul ON envoi_tentatives;
            CREATE TRIGGER envoi_tentatives_ajout_seul BEFORE UPDATE OR DELETE ON envoi_tentatives
                FOR EACH ROW EXECUTE FUNCTION envoi_tentatives_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_tentatives_pas_de_truncate ON envoi_tentatives;
            CREATE TRIGGER envoi_tentatives_pas_de_truncate BEFORE TRUNCATE ON envoi_tentatives
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_tentatives_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_tentatives FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_tentatives TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_tentatives_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des tentatives est conservé. */
    public function down(): void {}
};
