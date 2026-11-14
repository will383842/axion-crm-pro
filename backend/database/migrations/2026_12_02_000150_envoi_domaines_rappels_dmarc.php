<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R15 — rappels DMARC d'un domaine d'envoi : un RAPPEL seulement (la politique se change dans le DNS, par un humain).
 * AJOUT SEUL : UPDATE, DELETE et TRUNCATE refusés par déclencheur ; RLS par espace. Aucune adresse stockée.
 * Élargit aussi le CHECK de statut des versions de configuration à « prete » (version préparée, jamais appliquée) :
 * le CHECK est remplacé par un plus large, aucune ligne n'est lue ni modifiée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_domaines_rappels_dmarc (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                domaine_id   BIGINT      NOT NULL REFERENCES envoi_domaines(id),
                politique    TEXT        NOT NULL CHECK (politique IN ('none', 'quarantine', 'reject')),
                rappel_le    TIMESTAMPTZ NULL,
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_domaines_rappels_dmarc_ws_dom ON envoi_domaines_rappels_dmarc (workspace_id, domaine_id, id DESC);
            ALTER TABLE envoi_domaines_rappels_dmarc ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_domaines_rappels_dmarc FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_domaines_rappels_dmarc_workspace_isolation ON envoi_domaines_rappels_dmarc;
            CREATE POLICY envoi_domaines_rappels_dmarc_workspace_isolation ON envoi_domaines_rappels_dmarc FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_domaines_rappels_dmarc_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_domaines_rappels_dmarc : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_domaines_rappels_dmarc_ajout_seul ON envoi_domaines_rappels_dmarc;
            CREATE TRIGGER envoi_domaines_rappels_dmarc_ajout_seul BEFORE UPDATE OR DELETE ON envoi_domaines_rappels_dmarc
                FOR EACH ROW EXECUTE FUNCTION envoi_domaines_rappels_dmarc_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_domaines_rappels_dmarc_pas_de_truncate ON envoi_domaines_rappels_dmarc;
            CREATE TRIGGER envoi_domaines_rappels_dmarc_pas_de_truncate BEFORE TRUNCATE ON envoi_domaines_rappels_dmarc
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_domaines_rappels_dmarc_ajout_seul();

            ALTER TABLE envoi_config_versions DROP CONSTRAINT IF EXISTS envoi_config_versions_statut_check;
            ALTER TABLE envoi_config_versions DROP CONSTRAINT IF EXISTS envoi_config_versions_statut_r15;
            ALTER TABLE envoi_config_versions ADD CONSTRAINT envoi_config_versions_statut_r15
                CHECK (statut IN ('appliquee', 'invalide', 'retour_arriere', 'derive_refusee', 'incertaine', 'prete')) NOT VALID;
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_domaines_rappels_dmarc FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_domaines_rappels_dmarc TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_domaines_rappels_dmarc_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les rappels sont conservés. */
    public function down(): void {}
};
