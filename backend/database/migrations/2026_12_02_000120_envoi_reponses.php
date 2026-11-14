<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R12 — texte des réponses reçues. AJOUT SEUL (déclencheur : ni UPDATE, ni DELETE, ni TRUNCATE), RLS par espace.
 * Objet, extrait (4 000 caractères au plus, texte seul) et adresse de l'expéditeur sont CHIFFRÉS (jamais en clair en base).
 * Conservation : cinq ans après réception (`conserver_jusqu_au`, informatif — rien ne s'efface, règle S8).
 * `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_reponses (
                id                    BIGSERIAL   PRIMARY KEY,
                workspace_id          UUID        NOT NULL REFERENCES workspaces(id),
                evenement_id          BIGINT      NOT NULL REFERENCES envoi_evenements(id),
                objet_chiffre         TEXT        NOT NULL,
                extrait_chiffre       TEXT        NOT NULL,
                expediteur_chiffre    TEXT        NOT NULL,
                empreinte_expediteur  CHAR(64)    NOT NULL,
                recue_le              TIMESTAMPTZ NOT NULL,
                conserver_jusqu_au    DATE        NOT NULL,
                cree_le               TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_reponses_evenement ON envoi_reponses (evenement_id);
            CREATE INDEX IF NOT EXISTS envoi_reponses_recue ON envoi_reponses (workspace_id, recue_le DESC, id DESC);
            CREATE INDEX IF NOT EXISTS envoi_reponses_empreinte ON envoi_reponses (workspace_id, empreinte_expediteur);
            CREATE OR REPLACE FUNCTION envoi_reponses_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_reponses : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_reponses_ajout_seul ON envoi_reponses;
            CREATE TRIGGER envoi_reponses_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_reponses
                FOR EACH ROW EXECUTE FUNCTION envoi_reponses_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_reponses_pas_de_truncate ON envoi_reponses;
            CREATE TRIGGER envoi_reponses_pas_de_truncate
                BEFORE TRUNCATE ON envoi_reponses
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_reponses_ajout_seul();
            ALTER TABLE envoi_reponses ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_reponses FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_reponses_workspace_isolation ON envoi_reponses;
            CREATE POLICY envoi_reponses_workspace_isolation ON envoi_reponses FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_reponses IS 'Envoi R12 : texte chiffre des reponses recues. Ajout seul. Conservation 5 ans, jamais de suppression.';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_reponses FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_reponses TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_reponses_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » et les lignes sont conservées. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
