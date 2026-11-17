<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PONT-LINKEDIN PL-M0A — registre d'opposition LinkedIn, AJOUT SEUL : un déclencheur refuse UPDATE, DELETE et TRUNCATE ;
 * droits SELECT + INSERT seulement. RLS ENABLE + FORCE sur app.current_workspace_id. Le profil n'est jamais en clair :
 * empreinte (HMAC du profil normalisé) + valeur chiffrée. Une ligne par (espace, empreinte). ADDITIF SEULEMENT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_oppositions_linkedin (
                id              UUID        PRIMARY KEY,
                workspace_id    UUID        NOT NULL REFERENCES workspaces(id),
                empreinte       TEXT        NOT NULL CHECK (empreinte ~ '^[0-9a-f]{64}$'),
                valeur_chiffree TEXT        NOT NULL,
                source          TEXT        NOT NULL CHECK (source IN ('prospection_linkedin', 'manuel')),
                motif           TEXT        NOT NULL CHECK (length(motif) BETWEEN 3 AND 200 AND position('@' IN motif) = 0),
                auteur          TEXT        NOT NULL,
                cree_le         TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_oppositions_linkedin_une_par_profil UNIQUE (workspace_id, empreinte)
            )
            SQL,
        );
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION envoi_oppositions_linkedin_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_oppositions_linkedin : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public
            SQL);
        DB::statement('DROP TRIGGER IF EXISTS envoi_oppositions_linkedin_ajout_seul ON envoi_oppositions_linkedin');
        DB::statement('CREATE TRIGGER envoi_oppositions_linkedin_ajout_seul BEFORE UPDATE OR DELETE ON envoi_oppositions_linkedin FOR EACH ROW EXECUTE FUNCTION envoi_oppositions_linkedin_ajout_seul()');
        DB::statement('DROP TRIGGER IF EXISTS envoi_oppositions_linkedin_pas_de_truncate ON envoi_oppositions_linkedin');
        DB::statement('CREATE TRIGGER envoi_oppositions_linkedin_pas_de_truncate BEFORE TRUNCATE ON envoi_oppositions_linkedin FOR EACH STATEMENT EXECUTE FUNCTION envoi_oppositions_linkedin_ajout_seul()');
        DB::statement('ALTER TABLE envoi_oppositions_linkedin ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE envoi_oppositions_linkedin FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS envoi_oppositions_linkedin_workspace_isolation ON envoi_oppositions_linkedin');
        DB::statement("CREATE POLICY envoi_oppositions_linkedin_workspace_isolation ON envoi_oppositions_linkedin FOR ALL
            USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
            WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))");
        DB::statement("COMMENT ON TABLE envoi_oppositions_linkedin IS 'PONT-LINKEDIN : registre d''opposition LinkedIn (empreinte du profil + valeur chiffrée), ajout seul.'");

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_oppositions_linkedin FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_oppositions_linkedin TO ' . $role);
        }
    }

    /** Retour arrière INERTE : le registre est conservé (ajout seul). */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
