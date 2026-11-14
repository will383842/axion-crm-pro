<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R17 — constats de réputation (Google Postmaster, Microsoft SNDS et JMRP, Orange), saisis à la main ou, plus
 * tard, par un adaptateur automatique. AJOUT SEUL : corriger = nouvelle ligne. UPDATE/DELETE/TRUNCATE refusés ;
 * RLS par espace ; droits SELECT + INSERT. Aucune adresse e-mail (ressource et preuve en sont vérifiées par CHECK).
 * Rejeu : index unique (espace, clé de rejeu). Conservation : indéfinie (jamais de suppression), aucune donnée de personne.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_reputation_constats (
                id             BIGSERIAL    PRIMARY KEY,
                workspace_id   UUID         NOT NULL REFERENCES workspaces(id),
                source         TEXT         NOT NULL CHECK (source IN ('google_postmaster', 'microsoft_snds', 'microsoft_jmrp', 'orange')),
                ressource_type TEXT         NOT NULL CHECK (ressource_type IN ('ip', 'domaine')),
                ressource      TEXT         NOT NULL CHECK (char_length(ressource) BETWEEN 3 AND 253 AND position('@' IN ressource) = 0),
                jour           DATE         NOT NULL,
                reputation     TEXT         NOT NULL CHECK (reputation IN ('bonne', 'moyenne', 'mauvaise', 'inconnue')),
                taux_plaintes  NUMERIC(6,5) NULL CHECK (taux_plaintes BETWEEN 0 AND 1),
                erreurs_auth   INTEGER      NULL CHECK (erreurs_auth >= 0),
                pieges         INTEGER      NULL CHECK (pieges >= 0),
                saisie         TEXT         NOT NULL CHECK (saisie IN ('manuelle', 'automatique')),
                preuve         TEXT         NOT NULL CHECK (char_length(preuve) BETWEEN 10 AND 500 AND position('@' IN preuve) = 0),
                auteur         TEXT         NOT NULL CHECK (char_length(auteur) BETWEEN 1 AND 100 AND position('@' IN auteur) = 0),
                cle_rejeu      TEXT         NOT NULL CHECK (char_length(cle_rejeu) BETWEEN 8 AND 100),
                empreinte      CHAR(64)     NOT NULL,
                pause_ouverte  BOOLEAN      NOT NULL DEFAULT FALSE,
                cree_le        TIMESTAMPTZ  NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_reputation_constats_rejeu ON envoi_reputation_constats (workspace_id, cle_rejeu);
            CREATE INDEX IF NOT EXISTS envoi_reputation_constats_ws_jour ON envoi_reputation_constats (workspace_id, jour DESC, id DESC);
            ALTER TABLE envoi_reputation_constats ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_reputation_constats FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_reputation_constats_workspace_isolation ON envoi_reputation_constats;
            CREATE POLICY envoi_reputation_constats_workspace_isolation ON envoi_reputation_constats FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
            CREATE OR REPLACE FUNCTION envoi_reputation_constats_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_reputation_constats : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_reputation_constats_ajout_seul ON envoi_reputation_constats;
            CREATE TRIGGER envoi_reputation_constats_ajout_seul BEFORE UPDATE OR DELETE ON envoi_reputation_constats
                FOR EACH ROW EXECUTE FUNCTION envoi_reputation_constats_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_reputation_constats_pas_de_truncate ON envoi_reputation_constats;
            CREATE TRIGGER envoi_reputation_constats_pas_de_truncate BEFORE TRUNCATE ON envoi_reputation_constats
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_reputation_constats_ajout_seul();
        SQL);

        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_reputation_constats FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_reputation_constats TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_reputation_constats_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : l'historique des constats est conservé. */
    public function down(): void {}
};
