<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L6 — échecs PROPRES À UN MESSAGE pendant la relève (une ligne par tentative). Au-delà du
 * seuil, le message est mis à l'écart (« à rattacher », revue humaine) et le curseur peut passer.
 * `motif` = classe technique de l'exception seulement (jamais de texte, jamais d'adresse).
 * AJOUT SEUL (trigger : ni UPDATE, ni DELETE, ni TRUNCATE), RLS par espace.
 * Aucune adresse en clair, aucun corps. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_echecs_releve (
                id                BIGSERIAL   PRIMARY KEY,
                workspace_id      UUID        NOT NULL REFERENCES workspaces(id),
                boite             TEXT        NOT NULL,
                empreinte_message CHAR(64)    NOT NULL,
                motif             TEXT        NOT NULL,
                cree_le           TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_echecs_releve_message ON envoi_echecs_releve (workspace_id, boite, empreinte_message);
            CREATE OR REPLACE FUNCTION envoi_echecs_releve_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_echecs_releve : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_echecs_releve_ajout_seul ON envoi_echecs_releve;
            CREATE TRIGGER envoi_echecs_releve_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_echecs_releve
                FOR EACH ROW EXECUTE FUNCTION envoi_echecs_releve_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_echecs_releve_pas_de_truncate ON envoi_echecs_releve;
            CREATE TRIGGER envoi_echecs_releve_pas_de_truncate
                BEFORE TRUNCATE ON envoi_echecs_releve
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_echecs_releve_ajout_seul();
            ALTER TABLE envoi_echecs_releve ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_echecs_releve FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_echecs_releve_workspace_isolation ON envoi_echecs_releve;
            CREATE POLICY envoi_echecs_releve_workspace_isolation ON envoi_echecs_releve FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_echecs_releve IS 'Envoi P0 L6 : tentatives echouees par message (compteur). Ajout seul.';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_echecs_releve FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_echecs_releve TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_echecs_releve_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » et les lignes sont conservées. */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
