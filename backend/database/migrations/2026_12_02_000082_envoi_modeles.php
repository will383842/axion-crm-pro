<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R08 — modèles de message versionnés. `envoi_modele_versions` est en AJOUT SEUL (restaurer = nouvelle version
 * recopiée) ; `envoi_modeles` ne se supprime jamais : l'archivage pose `archive_le` (une seule fois), seule colonne
 * modifiable. UPDATE/DELETE/TRUNCATE refusés ailleurs. RLS par espace. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_modeles (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                nom          TEXT        NOT NULL CHECK (char_length(nom) BETWEEN 1 AND 200),
                cree_par     TEXT        NOT NULL CHECK (char_length(cree_par) BETWEEN 1 AND 100),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                archive_le   TIMESTAMPTZ NULL,
                CONSTRAINT envoi_modeles_id_ws UNIQUE (id, workspace_id)
            );
            CREATE TABLE IF NOT EXISTS envoi_modele_versions (
                id           BIGSERIAL   PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                modele_id    UUID        NOT NULL,
                numero       INTEGER     NOT NULL CHECK (numero >= 1),
                objet        TEXT        NOT NULL CHECK (char_length(objet) BETWEEN 1 AND 300),
                corps        TEXT        NOT NULL CHECK (char_length(corps) BETWEEN 1 AND 20000),
                auteur       TEXT        NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100),
                restaure_de  INTEGER     NULL CHECK (restaure_de IS NULL OR restaure_de >= 1),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_modele_versions_numero_unique UNIQUE (modele_id, numero),
                CONSTRAINT envoi_modele_versions_modele FOREIGN KEY (modele_id, workspace_id) REFERENCES envoi_modeles (id, workspace_id)
            );
            ALTER TABLE envoi_modeles ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_modeles FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_modeles_workspace_isolation ON envoi_modeles;
            CREATE POLICY envoi_modeles_workspace_isolation ON envoi_modeles FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            ALTER TABLE envoi_modele_versions ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_modele_versions FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_modele_versions_workspace_isolation ON envoi_modele_versions;
            CREATE POLICY envoi_modele_versions_workspace_isolation ON envoi_modele_versions FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_modeles_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                IF TG_TABLE_NAME = 'envoi_modeles' THEN
                    IF TG_OP = 'UPDATE' THEN
                        IF OLD.archive_le IS NULL AND NEW.archive_le IS NOT NULL
                           AND (NEW.id, NEW.workspace_id, NEW.nom, NEW.cree_par, NEW.cree_le) IS NOT DISTINCT FROM (OLD.id, OLD.workspace_id, OLD.nom, OLD.cree_par, OLD.cree_le) THEN
                            RETURN NEW;   -- archivage : la seule modification permise
                        END IF;
                    END IF;
                END IF;
                RAISE EXCEPTION '% : ajout seulement, archivage par archive_le (UPDATE/DELETE/TRUNCATE refusés)', TG_TABLE_NAME;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_modeles_ajout_seul ON envoi_modeles;
            CREATE TRIGGER envoi_modeles_ajout_seul BEFORE UPDATE OR DELETE ON envoi_modeles FOR EACH ROW EXECUTE FUNCTION envoi_modeles_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_modeles_pas_de_truncate ON envoi_modeles;
            CREATE TRIGGER envoi_modeles_pas_de_truncate BEFORE TRUNCATE ON envoi_modeles FOR EACH STATEMENT EXECUTE FUNCTION envoi_modeles_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_modele_versions_ajout_seul ON envoi_modele_versions;
            CREATE TRIGGER envoi_modele_versions_ajout_seul BEFORE UPDATE OR DELETE ON envoi_modele_versions FOR EACH ROW EXECUTE FUNCTION envoi_modeles_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_modele_versions_pas_de_truncate ON envoi_modele_versions;
            CREATE TRIGGER envoi_modele_versions_pas_de_truncate BEFORE TRUNCATE ON envoi_modele_versions FOR EACH STATEMENT EXECUTE FUNCTION envoi_modeles_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_modeles, public.envoi_modele_versions FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_modeles, public.envoi_modele_versions TO ' . $role);
            DB::statement('GRANT UPDATE (archive_le) ON public.envoi_modeles TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_modele_versions_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : modèles et versions sont conservés. */
    public function down(): void {}
};
