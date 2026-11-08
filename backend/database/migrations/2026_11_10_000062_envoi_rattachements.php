<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L6 — rattachement ULTÉRIEUR (revue humaine) d'un événement « a_rattacher » à une injection :
 * on AJOUTE une ligne ici, on ne modifie JAMAIS l'événement (qui est lui-même ajout seul).
 * AJOUT SEUL (trigger : ni UPDATE, ni DELETE, ni TRUNCATE), RLS par espace.
 * Aucune adresse en clair, aucun corps. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_rattachements (
                id                     BIGSERIAL   PRIMARY KEY,
                workspace_id           UUID        NOT NULL REFERENCES workspaces(id),
                evenement_id           BIGINT      NOT NULL REFERENCES envoi_evenements(id),
                id_injection           UUID        NOT NULL,
                empreinte_destinataire CHAR(64)    NOT NULL,
                decide_par             TEXT        NOT NULL,
                cree_le                TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_rattachements_evenement ON envoi_rattachements (evenement_id);
            CREATE OR REPLACE FUNCTION envoi_rattachements_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_rattachements : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_rattachements_ajout_seul ON envoi_rattachements;
            CREATE TRIGGER envoi_rattachements_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_rattachements
                FOR EACH ROW EXECUTE FUNCTION envoi_rattachements_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_rattachements_pas_de_truncate ON envoi_rattachements;
            CREATE TRIGGER envoi_rattachements_pas_de_truncate
                BEFORE TRUNCATE ON envoi_rattachements
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_rattachements_ajout_seul();
            CREATE OR REPLACE FUNCTION envoi_verifie_id_injection() RETURNS trigger AS $f$
            BEGIN
                IF NEW.id_injection IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM envoi_injections_smtp i WHERE i.id_injection = NEW.id_injection AND i.workspace_id = NEW.workspace_id
                ) THEN
                    RAISE EXCEPTION 'id_injection inconnu pour cet espace (envoi_injections_smtp)' USING ERRCODE = '23503';
                END IF;
                RETURN NEW;
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_rattachements_id_injection ON envoi_rattachements;
            CREATE TRIGGER envoi_rattachements_id_injection
                BEFORE INSERT ON envoi_rattachements
                FOR EACH ROW EXECUTE FUNCTION envoi_verifie_id_injection();
            ALTER TABLE envoi_rattachements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_rattachements FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_rattachements_workspace_isolation ON envoi_rattachements;
            CREATE POLICY envoi_rattachements_workspace_isolation ON envoi_rattachements FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_rattachements IS 'Envoi P0 L6 : rattachements ulterieurs (revue humaine). Ajout seul.';
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_rattachements FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_rattachements TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_rattachements_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » et les lignes sont conservées. */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
