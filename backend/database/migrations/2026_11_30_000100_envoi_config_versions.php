<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K18 — versions de configuration du serveur d'envoi : empreinte + statut, SANS contenu.
 * ADDITIF SEULEMENT, AJOUT SEUL (un trigger refuse UPDATE/DELETE/TRUNCATE). `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_config_versions (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL, -- pas de clé étrangère : l'historique survit à tout
                empreinte    TEXT        NOT NULL CHECK (empreinte ~ '^[0-9a-f]{64}$'),
                statut       TEXT        NOT NULL CHECK (statut IN ('appliquee', 'invalide', 'retour_arriere', 'derive_refusee', 'incertaine')),
                motif        TEXT        NULL,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            )
            SQL);
        DB::statement('CREATE INDEX IF NOT EXISTS envoi_config_versions_ws ON envoi_config_versions (workspace_id, id DESC)');
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_config_versions_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_config_versions : ajout seulement (UPDATE/DELETE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_config_versions_ajout_seul ON envoi_config_versions');
        DB::statement('CREATE TRIGGER envoi_config_versions_ajout_seul BEFORE UPDATE OR DELETE ON envoi_config_versions FOR EACH ROW EXECUTE FUNCTION envoi_config_versions_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS envoi_config_versions_pas_de_truncate ON envoi_config_versions');
        DB::statement('CREATE TRIGGER envoi_config_versions_pas_de_truncate BEFORE TRUNCATE ON envoi_config_versions FOR EACH STATEMENT EXECUTE FUNCTION envoi_config_versions_ajout_seul()');
        DB::statement('ALTER TABLE envoi_config_versions ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_config_versions FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_config_versions_workspace_isolation ON envoi_config_versions');
        DB::statement("CREATE POLICY envoi_config_versions_workspace_isolation ON envoi_config_versions FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE envoi_config_versions IS 'Envoi K18 : empreintes des versions de configuration du serveur, ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('GRANT SELECT, INSERT ON public.envoi_config_versions TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_config_versions_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique est conservé (ajout seul). */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
