<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R08 — modèles de message versionnés, alignés sur `creation.yaml` (MessageTypeEtat : `statut` brouillon|a_relire|valide|archive
 * et `version_validee`, la seule version utilisable dans une campagne).
 * - `envoi_modele_versions` : AJOUT SEUL (UPDATE/DELETE/TRUNCATE refusés) ; restaurer = nouvelle version recopiée (`restaure_de`,
 *   clé étrangère vers une version du même modèle). Une version validée est donc immuable.
 * - `envoi_modeles` : jamais supprimé (DELETE/TRUNCATE refusés) ; naît brouillon ; seules `statut`, `version_validee` et `archive_le`
 *   évoluent, par les transitions du contrat (une seule fonction SQL, miroir de DepotModeles::TRANSITIONS). `valide` exige une
 *   `version_validee` ; brouillon/a_relire n'en ont pas ; `archive_le` = now() posé avec le statut `archive`, jamais une date libre.
 *   Le nom ne change pas (un renommage = un nouveau modèle).
 * RLS par espace. ADDITIF SEULEMENT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_modeles (
                id             UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id   UUID        NOT NULL REFERENCES workspaces(id),
                nom            TEXT        NOT NULL CHECK (char_length(nom) BETWEEN 1 AND 200),
                statut         TEXT        NOT NULL DEFAULT 'brouillon' CHECK (statut IN ('brouillon', 'a_relire', 'valide', 'archive')),
                version_validee INTEGER    NULL CHECK (version_validee IS NULL OR version_validee >= 1),
                cree_par       TEXT        NOT NULL CHECK (char_length(cree_par) BETWEEN 1 AND 100),
                cree_le        TIMESTAMPTZ NOT NULL DEFAULT now(),
                archive_le     TIMESTAMPTZ NULL,
                CONSTRAINT envoi_modeles_id_ws UNIQUE (id, workspace_id),
                CONSTRAINT envoi_modeles_validation CHECK (
                    (statut = 'valide' AND version_validee IS NOT NULL)
                    OR (statut IN ('brouillon', 'a_relire') AND version_validee IS NULL)
                    OR statut = 'archive'),
                CONSTRAINT envoi_modeles_archive CHECK ((statut = 'archive') = (archive_le IS NOT NULL))
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
                CONSTRAINT envoi_modele_versions_modele FOREIGN KEY (modele_id, workspace_id) REFERENCES envoi_modeles (id, workspace_id),
                CONSTRAINT envoi_modele_versions_restaure FOREIGN KEY (modele_id, restaure_de) REFERENCES envoi_modele_versions (modele_id, numero)
            );
            DO $b$ BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_modeles_version_validee') THEN
                    ALTER TABLE envoi_modeles ADD CONSTRAINT envoi_modeles_version_validee
                        FOREIGN KEY (id, version_validee) REFERENCES envoi_modele_versions (modele_id, numero) DEFERRABLE INITIALLY IMMEDIATE;
                END IF;
            END $b$;
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

            -- UNE seule matrice SQL (miroir de DepotModeles::TRANSITIONS, comparée par un test).
            CREATE OR REPLACE FUNCTION envoi_modele_transition_permise(de TEXT, vers TEXT) RETURNS BOOLEAN AS $f$
                SELECT (de = 'brouillon' AND vers IN ('a_relire', 'archive'))
                    OR (de = 'a_relire'  AND vers IN ('brouillon', 'valide', 'archive'))
                    OR (de = 'valide'    AND vers IN ('brouillon', 'a_relire', 'archive'))
            $f$ LANGUAGE sql IMMUTABLE SET search_path = pg_catalog, public;
            CREATE OR REPLACE FUNCTION envoi_modeles_creation() RETURNS trigger AS $f$
            BEGIN
                IF NEW.statut <> 'brouillon' OR NEW.version_validee IS NOT NULL OR NEW.archive_le IS NOT NULL THEN
                    RAISE EXCEPTION 'envoi_modeles : un modèle naît brouillon, sans version validée';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            CREATE OR REPLACE FUNCTION envoi_modeles_garde() RETURNS trigger AS $f$
            BEGIN
                IF (NEW.id, NEW.workspace_id, NEW.nom, NEW.cree_par, NEW.cree_le) IS DISTINCT FROM (OLD.id, OLD.workspace_id, OLD.nom, OLD.cree_par, OLD.cree_le) THEN
                    RAISE EXCEPTION 'envoi_modeles : id, espace, nom, créateur et date de création sont figés';
                END IF;
                IF NEW.statut <> OLD.statut AND NOT envoi_modele_transition_permise(OLD.statut, NEW.statut) THEN
                    RAISE EXCEPTION 'envoi_modeles : transition % -> % interdite', OLD.statut, NEW.statut;
                END IF;
                -- la version validée ne bouge qu'AVEC un changement de statut (valide -> brouillon/a_relire l'efface, a_relire -> valide la pose),
                -- et l'archivage garde celle qui était validée : jamais une autre.
                IF (NEW.statut = OLD.statut OR NEW.statut = 'archive') AND NEW.version_validee IS DISTINCT FROM OLD.version_validee THEN
                    RAISE EXCEPTION 'envoi_modeles : la version validée est immuable';
                END IF;
                IF NEW.archive_le IS DISTINCT FROM OLD.archive_le AND (OLD.archive_le IS NOT NULL OR NEW.archive_le <> now()) THEN
                    RAISE EXCEPTION 'envoi_modeles : archive_le = now(), posé une seule fois';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            CREATE OR REPLACE FUNCTION envoi_modeles_pas_de_suppression() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION '% : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)', TG_TABLE_NAME;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_modeles_creation ON envoi_modeles;
            CREATE TRIGGER envoi_modeles_creation BEFORE INSERT ON envoi_modeles FOR EACH ROW EXECUTE FUNCTION envoi_modeles_creation();
            DROP TRIGGER IF EXISTS envoi_modeles_garde ON envoi_modeles;
            CREATE TRIGGER envoi_modeles_garde BEFORE UPDATE ON envoi_modeles FOR EACH ROW EXECUTE FUNCTION envoi_modeles_garde();
            DROP TRIGGER IF EXISTS envoi_modeles_ajout_seul ON envoi_modeles;
            CREATE TRIGGER envoi_modeles_ajout_seul BEFORE DELETE ON envoi_modeles FOR EACH ROW EXECUTE FUNCTION envoi_modeles_pas_de_suppression();
            DROP TRIGGER IF EXISTS envoi_modeles_pas_de_truncate ON envoi_modeles;
            CREATE TRIGGER envoi_modeles_pas_de_truncate BEFORE TRUNCATE ON envoi_modeles FOR EACH STATEMENT EXECUTE FUNCTION envoi_modeles_pas_de_suppression();
            DROP TRIGGER IF EXISTS envoi_modele_versions_ajout_seul ON envoi_modele_versions;
            CREATE TRIGGER envoi_modele_versions_ajout_seul BEFORE UPDATE OR DELETE ON envoi_modele_versions FOR EACH ROW EXECUTE FUNCTION envoi_modeles_pas_de_suppression();
            DROP TRIGGER IF EXISTS envoi_modele_versions_pas_de_truncate ON envoi_modele_versions;
            CREATE TRIGGER envoi_modele_versions_pas_de_truncate BEFORE TRUNCATE ON envoi_modele_versions FOR EACH STATEMENT EXECUTE FUNCTION envoi_modeles_pas_de_suppression();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_modeles, public.envoi_modele_versions FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_modeles, public.envoi_modele_versions TO ' . $role);
            DB::statement('GRANT UPDATE (statut, version_validee, archive_le) ON public.envoi_modeles TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_modele_versions_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : modèles et versions sont conservés. */
    public function down(): void {}
};
