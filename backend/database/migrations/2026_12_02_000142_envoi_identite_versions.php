<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R14 — versions de l'identité d'expéditeur (mentions, adresse postale, signature) ; la dernière fait foi. Texte libre de l'entreprise, aucune donnée de personne.
 * AJOUT SEUL : UPDATE, DELETE et TRUNCATE refusés par déclencheur ; RLS par espace. Aucune adresse stockée.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_identite_versions (
                id               BIGSERIAL   PRIMARY KEY,
                workspace_id     UUID        NOT NULL REFERENCES workspaces(id),
                raison_sociale   TEXT        NOT NULL CHECK (char_length(raison_sociale) <= 200),
                forme            TEXT        NOT NULL CHECK (char_length(forme) <= 50),
                adresse_postale  TEXT        NOT NULL CHECK (char_length(adresse_postale) <= 500),
                signature        TEXT        NOT NULL CHECK (char_length(signature) <= 2000),
                lien_rendez_vous TEXT        NULL CHECK (char_length(lien_rendez_vous) <= 500),
                bloc_information TEXT        NOT NULL CHECK (char_length(bloc_information) <= 4000),
                auteur           TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                cree_le          TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_identite_versions_ws ON envoi_identite_versions (workspace_id, id DESC);
            ALTER TABLE envoi_identite_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_identite_versions FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_identite_versions_workspace_isolation ON envoi_identite_versions;
            CREATE POLICY envoi_identite_versions_workspace_isolation ON envoi_identite_versions FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_identite_versions_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_identite_versions : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_identite_versions_ajout_seul ON envoi_identite_versions;
            CREATE TRIGGER envoi_identite_versions_ajout_seul BEFORE UPDATE OR DELETE ON envoi_identite_versions
                FOR EACH ROW EXECUTE FUNCTION envoi_identite_versions_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_identite_versions_pas_de_truncate ON envoi_identite_versions;
            CREATE TRIGGER envoi_identite_versions_pas_de_truncate BEFORE TRUNCATE ON envoi_identite_versions
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_identite_versions_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_identite_versions FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_identite_versions TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_identite_versions_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des versions est conservé. */
    public function down(): void {}
};
