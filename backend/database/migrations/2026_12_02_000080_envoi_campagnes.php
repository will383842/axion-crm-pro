<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R08 — campagnes (modèle de données SEUL : aucune route, aucun lien avec envoi_sequences/envoi_inscriptions).
 * - cible bornée (type, référence, libellé) : aucune adresse e-mail (pas de « @ ») ; saisie bornée à 64 Ko ;
 * - en_cours / en_pause / terminee / arretee IMPOSSIBLES sans sequence_id (CHECK) ;
 * - INSERT : déclencheur BEFORE INSERT, toute campagne naît « brouillon », version 1, sans sequence_id (pour TOUS les rôles) ;
 * - DELETE et TRUNCATE refusés par déclencheur (aucune suppression, même par le propriétaire) ; le rôle applicatif n'a AUCUN droit sur la colonne
 *   sequence_id (ni INSERT ni UPDATE) : une campagne ne peut pas devenir exécutable par ce chemin ;
 * - transitions de statut vérifiées par déclencheur (même matrice que App\Envoi\Campagnes\DepotCampagnes::TRANSITIONS) ;
 * - verrou optimiste : toute modification incrémente `version` de 1 exactement ; pas de DELETE pour le rôle applicatif.
 * ADDITIF SEULEMENT. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_campagnes (
                id           UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                nom          TEXT        NOT NULL CHECK (char_length(nom) BETWEEN 1 AND 200),
                statut       TEXT        NOT NULL DEFAULT 'brouillon'
                             CHECK (statut IN ('brouillon', 'a_valider', 'programmee', 'en_cours', 'en_pause', 'terminee', 'arretee')),
                cible        JSONB       NOT NULL CHECK (
                                 jsonb_typeof(cible) = 'object'
                                 AND cible ?& ARRAY['type', 'reference', 'libelle']
                                 AND (cible - 'type' - 'reference' - 'libelle') = '{}'::jsonb
                                 AND cible->>'type' IN ('liste', 'audience', 'etiquette', 'carnet')
                                 AND jsonb_typeof(cible->'reference') = 'string' AND jsonb_typeof(cible->'libelle') = 'string'
                                 AND strpos(cible::text, '@') = 0
                                 AND octet_length(cible::text) <= 2048),
                boites       UUID[]      NOT NULL DEFAULT '{}' CHECK (cardinality(boites) <= 50),
                nb_etapes    SMALLINT    NOT NULL CHECK (nb_etapes BETWEEN 1 AND 3),
                saisie       JSONB       NOT NULL DEFAULT '{}' CHECK (jsonb_typeof(saisie) = 'object' AND octet_length(saisie::text) <= 65536),
                version      INTEGER     NOT NULL DEFAULT 1 CHECK (version >= 1),
                sequence_id  UUID        NULL,
                cree_par     TEXT        NOT NULL CHECK (char_length(cree_par) BETWEEN 1 AND 100),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le       TIMESTAMPTZ NOT NULL DEFAULT now(),
                CONSTRAINT envoi_campagnes_executable_sans_sequence CHECK (statut NOT IN ('en_cours', 'en_pause', 'terminee', 'arretee') OR sequence_id IS NOT NULL),
                CONSTRAINT envoi_campagnes_id_ws UNIQUE (id, workspace_id)
            );
            CREATE INDEX IF NOT EXISTS envoi_campagnes_ws_statut ON envoi_campagnes (workspace_id, statut, cree_le DESC);
            ALTER TABLE envoi_campagnes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_campagnes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_campagnes_workspace_isolation ON envoi_campagnes;
            CREATE POLICY envoi_campagnes_workspace_isolation ON envoi_campagnes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            -- UNE seule matrice des transitions côté base (le déclencheur de la campagne ET celui du journal l'utilisent) ;
            -- App\Envoi\Campagnes\DepotCampagnes::TRANSITIONS dit la même chose (un test compare les 49 couples).
            CREATE OR REPLACE FUNCTION envoi_campagnes_transition_permise(de TEXT, vers TEXT) RETURNS BOOLEAN AS $f$
                SELECT (de = 'brouillon' AND vers = 'a_valider')
                    OR (de = 'a_valider' AND vers IN ('brouillon', 'programmee'))
                    OR (de = 'programmee' AND vers IN ('a_valider', 'en_cours', 'arretee'))
                    OR (de = 'en_cours' AND vers IN ('en_pause', 'terminee', 'arretee'))
                    OR (de = 'en_pause' AND vers IN ('en_cours', 'terminee', 'arretee'))
            $f$ LANGUAGE sql IMMUTABLE SET search_path = pg_catalog, public;
            CREATE OR REPLACE FUNCTION envoi_campagnes_garde() RETURNS trigger AS $f$
            BEGIN
                IF NEW.id <> OLD.id OR NEW.workspace_id <> OLD.workspace_id OR NEW.cree_par <> OLD.cree_par OR NEW.cree_le <> OLD.cree_le THEN
                    RAISE EXCEPTION 'envoi_campagnes : id, espace, créateur et date de création sont figés';
                END IF;
                IF NEW.version <> OLD.version + 1 THEN
                    RAISE EXCEPTION 'envoi_campagnes : version périmée ou saut de version (attendu %)', OLD.version + 1;
                END IF;
                IF NEW.statut <> OLD.statut AND NOT envoi_campagnes_transition_permise(OLD.statut, NEW.statut) THEN
                    RAISE EXCEPTION 'envoi_campagnes : transition % -> % interdite', OLD.statut, NEW.statut;
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_garde ON envoi_campagnes;
            CREATE TRIGGER envoi_campagnes_garde BEFORE UPDATE ON envoi_campagnes FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_garde();
            CREATE OR REPLACE FUNCTION envoi_campagnes_garde_insert() RETURNS trigger AS $f$
            BEGIN
                IF NEW.statut <> 'brouillon' OR NEW.version <> 1 OR NEW.sequence_id IS NOT NULL THEN
                    RAISE EXCEPTION 'envoi_campagnes : une campagne naît brouillon, version 1, sans séquence (reçu % / % / %)', NEW.statut, NEW.version, NEW.sequence_id;
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_garde_insert ON envoi_campagnes;
            CREATE TRIGGER envoi_campagnes_garde_insert BEFORE INSERT ON envoi_campagnes FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_garde_insert();
            CREATE OR REPLACE FUNCTION envoi_campagnes_sans_suppression() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_campagnes : aucune suppression (DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_pas_de_delete ON envoi_campagnes;
            CREATE TRIGGER envoi_campagnes_pas_de_delete BEFORE DELETE ON envoi_campagnes FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_sans_suppression();
            DROP TRIGGER IF EXISTS envoi_campagnes_pas_de_truncate ON envoi_campagnes;
            CREATE TRIGGER envoi_campagnes_pas_de_truncate BEFORE TRUNCATE ON envoi_campagnes FOR EACH STATEMENT EXECUTE FUNCTION envoi_campagnes_sans_suppression();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_campagnes FROM ' . $role);
            DB::statement('GRANT SELECT ON public.envoi_campagnes TO ' . $role);
            // Volontairement SANS sequence_id : le rôle applicatif ne peut ni la poser ni la changer.
            DB::statement('GRANT INSERT (id, workspace_id, nom, statut, cible, boites, nb_etapes, saisie, version, cree_par, cree_le, maj_le) ON public.envoi_campagnes TO ' . $role);
            DB::statement('GRANT UPDATE (nom, statut, cible, boites, nb_etapes, saisie, version, maj_le) ON public.envoi_campagnes TO ' . $role);
        }
    }

    /** Retour arrière INERTE : les campagnes sont conservées. */
    public function down(): void {}
};
