<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi FINI-M0B — attestation « serveur réel » : le responsable atteste (ou retire) à la main que le serveur d'envoi réel
 * est en place. La DERNIÈRE ligne de l'espace fait foi. AJOUT SEUL (UPDATE/DELETE/TRUNCATE refusés), RLS forcée par espace.
 * Le motif est un texte libre court, sans adresse (aucun « @ »). Migration seule : rien ne la lit encore. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_attestations_serveur_reel (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                geste        TEXT        NOT NULL CHECK (geste IN ('atteste', 'retire')),
                auteur       TEXT        NOT NULL CHECK (length(auteur) > 0),
                motif        TEXT        NOT NULL CHECK (length(motif) BETWEEN 10 AND 500 AND position('@' in motif) = 0),
                le           TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_attestations_serveur_reel_ws ON envoi_attestations_serveur_reel (workspace_id, le DESC);
            ALTER TABLE envoi_attestations_serveur_reel ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_attestations_serveur_reel FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_attestations_serveur_reel_workspace_isolation ON envoi_attestations_serveur_reel;
            CREATE POLICY envoi_attestations_serveur_reel_workspace_isolation ON envoi_attestations_serveur_reel FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_attestations_serveur_reel_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_attestations_serveur_reel : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_attestations_serveur_reel_ajout_seul ON envoi_attestations_serveur_reel;
            CREATE TRIGGER envoi_attestations_serveur_reel_ajout_seul BEFORE UPDATE OR DELETE ON envoi_attestations_serveur_reel
                FOR EACH ROW EXECUTE FUNCTION envoi_attestations_serveur_reel_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_attestations_serveur_reel_pas_de_truncate ON envoi_attestations_serveur_reel;
            CREATE TRIGGER envoi_attestations_serveur_reel_pas_de_truncate BEFORE TRUNCATE ON envoi_attestations_serveur_reel
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_attestations_serveur_reel_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_attestations_serveur_reel FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_attestations_serveur_reel TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les attestations posées sont conservées. */
    public function down(): void {}
};
