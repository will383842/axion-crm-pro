<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-B (D10) — essais à blanc : une ligne par essai terminé (auteur, début, fin, réussi, étapes en JSONB). AJOUT SEUL.
 * RLS forcée par espace, UPDATE/DELETE/TRUNCATE refusés, droits SELECT + INSERT. Aucune adresse dans la base d'envoi :
 * un CHECK refuse tout `@` dans `auteur` et dans `etapes`. down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_essais_blanc (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100 AND position('@' IN auteur) = 0),
                debut        TIMESTAMPTZ NOT NULL,
                fin          TIMESTAMPTZ NOT NULL,
                reussi       BOOLEAN     NOT NULL,
                etapes       JSONB       NOT NULL DEFAULT '[]'::jsonb CHECK (position('@' IN etapes::text) = 0),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CHECK (fin >= debut)
            );
            CREATE INDEX IF NOT EXISTS envoi_essais_blanc_dernier ON envoi_essais_blanc (workspace_id, cree_le DESC);
            ALTER TABLE envoi_essais_blanc ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_essais_blanc FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_essais_blanc_workspace_isolation ON envoi_essais_blanc;
            CREATE POLICY envoi_essais_blanc_workspace_isolation ON envoi_essais_blanc FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_essais_blanc_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_essais_blanc : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_essais_blanc_ajout_seul ON envoi_essais_blanc;
            CREATE TRIGGER envoi_essais_blanc_ajout_seul BEFORE UPDATE OR DELETE ON envoi_essais_blanc
                FOR EACH ROW EXECUTE FUNCTION envoi_essais_blanc_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_essais_blanc_pas_de_truncate ON envoi_essais_blanc;
            CREATE TRIGGER envoi_essais_blanc_pas_de_truncate BEFORE TRUNCATE ON envoi_essais_blanc
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_essais_blanc_ajout_seul();
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_essais_blanc FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_essais_blanc TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des essais est conservé. */
    public function down(): void {}
};
