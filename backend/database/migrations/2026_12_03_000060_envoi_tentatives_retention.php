<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi S1-A (D16) — conservation de `envoi_tentatives` : détail gardé 400 jours, puis agrégé par jour. AJOUTS SEULS.
 *
 * - Table `envoi_tentatives_jours` : espace, jour (Europe/Paris), fournisseur, issue, code SMTP, nombre. Aucune donnée de personne.
 *   Ajout seul (UPDATE/DELETE/TRUNCATE refusés), sans clé étrangère vers `workspaces`, RLS forcée, clé UNIQUE NULLS NOT DISTINCT
 *   (sinon un code SMTP NULL permettrait des doublons). Le rôle applicatif n'a que SELECT : seule la fonction écrit.
 * - `envoi_tentatives_archiver(p_ws, p_avant)` (SECURITY DEFINER) : refuse un espace différent du contexte (ou l'absence de contexte)
 *   et une borne plus récente que « aujourd'hui (Paris) - 400 jours » ; agrège PUIS supprime le détail de `p_ws` seul (filtre
 *   explicite : le propriétaire ne contourne la RLS que s'il est SUPERUSER ou BYPASSRLS), dans la même transaction ; jours entiers seulement. Un conflit de clé annule tout
 *   (rien n'est supprimé sans avoir été compté). La clause SET pose `envoi.archivage_tentatives = 'on'` et la remet à la sortie.
 * - Le déclencheur d'ajout seul de `envoi_tentatives` est remplacé : un DELETE n'y passe que si la variable vaut `on` ET si la ligne
 *   a plus de 400 jours (même borne). La variable n'est jamais une barrière : le filtre de la fonction, ce déclencheur et l'absence
 *   de droit DELETE du rôle applicatif le sont. UPDATE et TRUNCATE restent toujours refusés.
 * Aucune ligne existante n'est modifiée. down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_tentatives_jours (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID      NOT NULL,
                jour         DATE      NOT NULL,
                fournisseur  TEXT      NOT NULL CHECK (fournisseur IN ('gmail', 'microsoft', 'autre')),
                issue        TEXT      NOT NULL CHECK (issue IN ('acceptee', 'reportee', 'refusee')),
                code_smtp    SMALLINT  NULL CHECK (code_smtp BETWEEN 200 AND 599),
                nombre       INTEGER   NOT NULL CHECK (nombre > 0),
                CONSTRAINT envoi_tentatives_jours_cle UNIQUE NULLS NOT DISTINCT (workspace_id, jour, fournisseur, issue, code_smtp)
            );
            ALTER TABLE envoi_tentatives_jours ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_tentatives_jours FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_tentatives_jours_workspace_isolation ON envoi_tentatives_jours;
            CREATE POLICY envoi_tentatives_jours_workspace_isolation ON envoi_tentatives_jours FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_tentatives_jours_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_tentatives_jours : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_tentatives_jours_ajout_seul ON envoi_tentatives_jours;
            CREATE TRIGGER envoi_tentatives_jours_ajout_seul BEFORE UPDATE OR DELETE ON envoi_tentatives_jours
                FOR EACH ROW EXECUTE FUNCTION envoi_tentatives_jours_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_tentatives_jours_pas_de_truncate ON envoi_tentatives_jours;
            CREATE TRIGGER envoi_tentatives_jours_pas_de_truncate BEFORE TRUNCATE ON envoi_tentatives_jours
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_tentatives_jours_ajout_seul();

            -- Déclencheur d'ajout seul de envoi_tentatives (remplacé). `TG_OP = 'DELETE'` est testé AVANT toute lecture de OLD
            -- (au niveau de l'instruction TRUNCATE, OLD est absent) ; la branche autorisée rend OLD (NEW, NULL, annulerait en silence).
            CREATE OR REPLACE FUNCTION envoi_tentatives_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP = 'DELETE' THEN
                    IF COALESCE(current_setting('envoi.archivage_tentatives', true), '') = 'on' THEN
                        IF OLD.cree_le < (((now() AT TIME ZONE 'Europe/Paris')::date - 400)::timestamp AT TIME ZONE 'Europe/Paris') THEN
                            RETURN OLD;
                        END IF;
                    END IF;
                END IF;
                RAISE EXCEPTION 'envoi_tentatives : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;

            CREATE OR REPLACE FUNCTION envoi_tentatives_archiver(p_ws UUID, p_avant DATE) RETURNS integer AS $f$
            DECLARE
                v_borne TIMESTAMPTZ;
                v_n INTEGER;
            BEGIN
                IF p_ws IS NULL OR p_avant IS NULL OR p_ws::TEXT IS DISTINCT FROM NULLIF(current_setting('app.current_workspace_id', true), '') THEN
                    RAISE EXCEPTION 'envoi_tentatives : archivage refusé (espace différent du contexte ou aucun contexte)';
                END IF;
                IF p_avant > (now() AT TIME ZONE 'Europe/Paris')::date - 400 THEN
                    RAISE EXCEPTION 'envoi_tentatives : archivage refusé (le détail est gardé 400 jours)';
                END IF;
                v_borne := p_avant::timestamp AT TIME ZONE 'Europe/Paris';
                INSERT INTO public.envoi_tentatives_jours (workspace_id, jour, fournisseur, issue, code_smtp, nombre)
                    SELECT t.workspace_id, (t.cree_le AT TIME ZONE 'Europe/Paris')::date, t.fournisseur, t.issue, t.code_smtp, count(*)
                    FROM public.envoi_tentatives t
                    WHERE t.workspace_id = p_ws AND t.cree_le < v_borne
                    GROUP BY 1, 2, 3, 4, 5;
                DELETE FROM public.envoi_tentatives WHERE workspace_id = p_ws AND cree_le < v_borne;
                GET DIAGNOSTICS v_n = ROW_COUNT;
                RETURN v_n;
            END;
            $f$ LANGUAGE plpgsql SECURITY DEFINER SET search_path = pg_catalog, public SET envoi.archivage_tentatives = 'on';
            REVOKE ALL ON FUNCTION envoi_tentatives_archiver(UUID, DATE) FROM PUBLIC;
        SQL);

        $nom = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($nom !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$nom]) !== null) {
            $role = '"' . str_replace('"', '""', $nom) . '"';
            DB::statement('REVOKE ALL ON public.envoi_tentatives_jours FROM ' . $role);
            DB::statement('GRANT SELECT ON public.envoi_tentatives_jours TO ' . $role);
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_tentatives FROM ' . $role);
            DB::statement('GRANT EXECUTE ON FUNCTION public.envoi_tentatives_archiver(UUID, DATE) TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique agrégé et les protections sont conservés. */
    public function down(): void {}
};
