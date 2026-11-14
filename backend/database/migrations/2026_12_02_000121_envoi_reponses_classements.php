<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R12 — gestes sur une réponse, AJOUT SEUL : le classement courant est la DERNIÈRE ligne `geste = 'classement'`.
 * `geste = 'fiche'` note le lien vers une fiche CRM (contact_id) sans changer le classement ni la file.
 * Un classement du classificateur est une suggestion : il ne ferme jamais la réponse (seul un humain la traite).
 * RLS par espace, déclencheur « ajout seul ». `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_reponses_classements (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                reponse_id   BIGINT      NOT NULL REFERENCES envoi_reponses(id),
                geste        TEXT        NOT NULL DEFAULT 'classement' CHECK (geste IN ('classement','fiche')),
                classement   TEXT        NOT NULL
                             CHECK (classement IN ('interesse','pas_maintenant','question','refus','stop','absence','a_rattacher')),
                confiance    SMALLINT    NOT NULL CHECK (confiance BETWEEN 0 AND 100),
                auteur       TEXT        NOT NULL CHECK (auteur IN ('humain','classificateur')),
                auteur_id    UUID,
                contact_id   BIGINT,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_reponses_classements_fiche CHECK ((geste = 'fiche') = (contact_id IS NOT NULL))
            );
            CREATE INDEX IF NOT EXISTS envoi_reponses_classements_reponse ON envoi_reponses_classements (reponse_id, id DESC);
            CREATE OR REPLACE FUNCTION envoi_reponses_classements_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_reponses_classements : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_reponses_classements_ajout_seul ON envoi_reponses_classements;
            CREATE TRIGGER envoi_reponses_classements_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_reponses_classements
                FOR EACH ROW EXECUTE FUNCTION envoi_reponses_classements_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_reponses_classements_pas_de_truncate ON envoi_reponses_classements;
            CREATE TRIGGER envoi_reponses_classements_pas_de_truncate
                BEFORE TRUNCATE ON envoi_reponses_classements
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_reponses_classements_ajout_seul();
            ALTER TABLE envoi_reponses_classements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_reponses_classements FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_reponses_classements_workspace_isolation ON envoi_reponses_classements;
            CREATE POLICY envoi_reponses_classements_workspace_isolation ON envoi_reponses_classements FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_reponses_classements IS 'Envoi R12 : classements et liens de fiche des reponses. Ajout seul.';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_reponses_classements FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_reponses_classements TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_reponses_classements_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
