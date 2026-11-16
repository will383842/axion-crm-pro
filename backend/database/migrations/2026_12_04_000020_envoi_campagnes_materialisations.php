<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi X1-L1 — matérialisation d'une campagne : une ligne par campagne, qui dit où en est la création de ses inscriptions
 * (curseur, compteurs bornés, état, motif de blocage). RLS FORCE par espace, clés étrangères composites (NO ACTION).
 * Déclencheur BEFORE UPDATE : campagne_id, workspace_id, cree_le figés ; sequence_id ne passe que de NULL à une valeur ; le curseur ne
 * décroît jamais ; en_cours -> terminee | bloquee seulement (terminee et bloquee sont terminales). DELETE et TRUNCATE refusés.
 * Droits du rôle applicatif : SELECT, INSERT, UPDATE sur sept colonnes seulement. ADDITIF SEULEMENT. `down()` inerte ; `up()` rejouable.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_campagnes_materialisations (
                campagne_id  UUID        PRIMARY KEY,
                workspace_id UUID        NOT NULL REFERENCES workspaces(id),
                sequence_id  UUID        NULL,
                etat         TEXT        NOT NULL DEFAULT 'en_cours' CHECK (etat IN ('en_cours', 'terminee', 'bloquee')),
                curseur      BIGINT      NOT NULL DEFAULT 0 CHECK (curseur >= 0),
                compteurs    JSONB       NOT NULL DEFAULT '{}' CHECK (
                                 jsonb_typeof(compteurs) = 'object'
                                 AND octet_length(compteurs::text) <= 2048
                                 AND NOT jsonb_path_exists(compteurs, '$.* ? (@.type() != "number")')),
                motif        TEXT        NULL CHECK (motif IS NULL OR motif IN ('jours_non_applicables', 'objet_b_non_applicable', 'sequence_incoherente',
                                 'cible_introuvable', 'etapes_invalides', 'espace_non_emetteur')),
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                maj_le       TIMESTAMPTZ NOT NULL DEFAULT now(),
                terminee_le  TIMESTAMPTZ NULL,
                CHECK (etat <> 'bloquee' OR motif IS NOT NULL),
                CONSTRAINT envoi_campagnes_materialisations_campagne_fk FOREIGN KEY (campagne_id, workspace_id) REFERENCES envoi_campagnes (id, workspace_id),
                CONSTRAINT envoi_campagnes_materialisations_sequence_fk FOREIGN KEY (workspace_id, sequence_id) REFERENCES envoi_sequences (workspace_id, id)
            );
            ALTER TABLE envoi_campagnes_materialisations ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_campagnes_materialisations FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_campagnes_materialisations_workspace_isolation ON envoi_campagnes_materialisations;
            CREATE POLICY envoi_campagnes_materialisations_workspace_isolation ON envoi_campagnes_materialisations FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));

            CREATE OR REPLACE FUNCTION envoi_campagnes_materialisations_garde() RETURNS trigger AS $f$
            BEGIN
                IF NEW.campagne_id <> OLD.campagne_id OR NEW.workspace_id <> OLD.workspace_id OR NEW.cree_le <> OLD.cree_le THEN
                    RAISE EXCEPTION 'envoi_campagnes_materialisations : campagne, espace et date de création sont figés';
                END IF;
                IF NEW.sequence_id IS DISTINCT FROM OLD.sequence_id AND (OLD.sequence_id IS NOT NULL OR NEW.sequence_id IS NULL) THEN
                    RAISE EXCEPTION 'envoi_campagnes_materialisations : sequence_id ne passe que de NULL à une valeur';
                END IF;
                IF NEW.curseur < OLD.curseur THEN
                    RAISE EXCEPTION 'envoi_campagnes_materialisations : le curseur ne décroît jamais';
                END IF;
                IF NEW.etat <> OLD.etat AND NOT (OLD.etat = 'en_cours' AND NEW.etat IN ('terminee', 'bloquee')) THEN
                    RAISE EXCEPTION 'envoi_campagnes_materialisations : transition % -> % interdite', OLD.etat, NEW.etat;
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_materialisations_garde ON envoi_campagnes_materialisations;
            CREATE TRIGGER envoi_campagnes_materialisations_garde BEFORE UPDATE ON envoi_campagnes_materialisations
                FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_materialisations_garde();

            CREATE OR REPLACE FUNCTION envoi_campagnes_materialisations_sans_suppression() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_campagnes_materialisations : aucune suppression (DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_campagnes_materialisations_pas_de_delete ON envoi_campagnes_materialisations;
            CREATE TRIGGER envoi_campagnes_materialisations_pas_de_delete BEFORE DELETE ON envoi_campagnes_materialisations
                FOR EACH ROW EXECUTE FUNCTION envoi_campagnes_materialisations_sans_suppression();
            DROP TRIGGER IF EXISTS envoi_campagnes_materialisations_pas_de_truncate ON envoi_campagnes_materialisations;
            CREATE TRIGGER envoi_campagnes_materialisations_pas_de_truncate BEFORE TRUNCATE ON envoi_campagnes_materialisations
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_campagnes_materialisations_sans_suppression();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_campagnes_materialisations FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_campagnes_materialisations TO ' . $role);
            DB::statement('GRANT UPDATE (sequence_id, etat, curseur, compteurs, motif, maj_le, terminee_le) ON public.envoi_campagnes_materialisations TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'état de matérialisation des campagnes est conservé. */
    public function down(): void {}
};
