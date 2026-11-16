<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi C3-0 — boîte d'envoi des retraits (R2). Une demande de retrait = une empreinte d'adresse (aucune adresse en clair).
 * Machine à états : en_attente -> envoye | sans_objet ; les états finals sont figés. DELETE/TRUNCATE refusés ;
 * identité (id, espace, empreinte, date de création) figée. RLS par espace. Droits : SELECT, INSERT, UPDATE de 5 colonnes.
 * AJOUT SEUL, INERTE : aucune lecture ni écriture applicative dans ce lot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_retraits_demandes (
                id                UUID        PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id      UUID        NOT NULL REFERENCES workspaces(id),
                empreinte_adresse CHAR(64)    NOT NULL CHECK (empreinte_adresse ~ '^[0-9a-f]{64}$'),
                statut            TEXT        NOT NULL DEFAULT 'en_attente' CHECK (statut IN ('en_attente', 'envoye', 'sans_objet')),
                commande_id       UUID,
                cibles_nb         INT         CHECK (cibles_nb IS NULL OR cibles_nb BETWEEN 0 AND 100),
                tentatives        INT         NOT NULL DEFAULT 0,
                cree_le           TIMESTAMPTZ NOT NULL DEFAULT now(),
                traite_le         TIMESTAMPTZ
            );
            CREATE INDEX IF NOT EXISTS envoi_retraits_demandes_attente ON envoi_retraits_demandes (workspace_id, cree_le) WHERE statut = 'en_attente';
            ALTER TABLE envoi_retraits_demandes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_retraits_demandes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_retraits_demandes_workspace_isolation ON envoi_retraits_demandes;
            CREATE POLICY envoi_retraits_demandes_workspace_isolation ON envoi_retraits_demandes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            COMMENT ON TABLE envoi_retraits_demandes IS 'Envoi C3-0 : boite des retraits (empreintes, jamais d adresse). Ajout seul, etats finals figes.';

            CREATE OR REPLACE FUNCTION envoi_retraits_demandes_garde() RETURNS trigger AS $f$
            BEGIN
                IF TG_OP <> 'UPDATE' THEN
                    RAISE EXCEPTION 'envoi_retraits_demandes : DELETE/TRUNCATE refusés';
                END IF;
                IF OLD.statut IN ('envoye', 'sans_objet') THEN
                    RAISE EXCEPTION 'envoi_retraits_demandes : état final figé (%)', OLD.statut;
                END IF;
                IF NEW.id IS DISTINCT FROM OLD.id OR NEW.workspace_id IS DISTINCT FROM OLD.workspace_id
                   OR NEW.empreinte_adresse IS DISTINCT FROM OLD.empreinte_adresse OR NEW.cree_le IS DISTINCT FROM OLD.cree_le THEN
                    RAISE EXCEPTION 'envoi_retraits_demandes : identité figée';
                END IF;
                RETURN NEW;
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_retraits_demandes_garde ON envoi_retraits_demandes;
            CREATE TRIGGER envoi_retraits_demandes_garde BEFORE UPDATE OR DELETE ON envoi_retraits_demandes
                FOR EACH ROW EXECUTE FUNCTION envoi_retraits_demandes_garde();
            DROP TRIGGER IF EXISTS envoi_retraits_demandes_pas_de_truncate ON envoi_retraits_demandes;
            CREATE TRIGGER envoi_retraits_demandes_pas_de_truncate BEFORE TRUNCATE ON envoi_retraits_demandes
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_retraits_demandes_garde();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE ALL ON public.envoi_retraits_demandes FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_retraits_demandes TO ' . $role);
            DB::statement('GRANT UPDATE (statut, commande_id, cibles_nb, tentatives, traite_le) ON public.envoi_retraits_demandes TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la boîte de retraits est conservée (la retirer effacerait des demandes). */
    public function down(): void {}
};
