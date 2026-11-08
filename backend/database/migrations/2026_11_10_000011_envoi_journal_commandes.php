<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L0 — journal des commandes, AJOUT SEUL : un trigger refuse UPDATE,
 * DELETE (par ligne) et TRUNCATE (par instruction) ; le rôle applicatif perd
 * UPDATE, DELETE et TRUNCATE. down() est INERTE : ni la table, ni ses lignes,
 * ni la protection ne sont retirées.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_journal_commandes (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL, -- pas de clé étrangère : le journal survit à tout, il ne se vide jamais
                commande     TEXT NOT NULL,
                auteur       TEXT NOT NULL,
                details      TEXT NOT NULL DEFAULT '{}',
                jour         DATE NOT NULL DEFAULT CURRENT_DATE,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX IF NOT EXISTS envoi_journal_commandes_ws_cmd_jour
                ON envoi_journal_commandes (workspace_id, commande, jour);
            CREATE OR REPLACE FUNCTION envoi_journal_commandes_ajout_seul() RETURNS trigger AS $f$
            BEGIN
                RAISE EXCEPTION 'envoi_journal_commandes : ajout seulement (UPDATE/DELETE/TRUNCATE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
            DROP TRIGGER IF EXISTS envoi_journal_commandes_ajout_seul ON envoi_journal_commandes;
            CREATE TRIGGER envoi_journal_commandes_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_journal_commandes
                FOR EACH ROW EXECUTE FUNCTION envoi_journal_commandes_ajout_seul();
            DROP TRIGGER IF EXISTS envoi_journal_commandes_pas_de_truncate ON envoi_journal_commandes;
            CREATE TRIGGER envoi_journal_commandes_pas_de_truncate
                BEFORE TRUNCATE ON envoi_journal_commandes
                FOR EACH STATEMENT EXECUTE FUNCTION envoi_journal_commandes_ajout_seul();
            ALTER TABLE envoi_journal_commandes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_journal_commandes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_journal_commandes_workspace_isolation ON envoi_journal_commandes;
            CREATE POLICY envoi_journal_commandes_workspace_isolation ON envoi_journal_commandes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
        SQL);

        // Défense en profondeur : le rôle applicatif n'a que SELECT et INSERT.
        $roleApplicatif = (string) config('database.connections.pgsql_app.username', 'axion_app');
        if ($roleApplicatif !== '' && DB::selectOne('SELECT 1 AS e FROM pg_roles WHERE rolname = ?', [$roleApplicatif]) !== null) {
            $role = '"' . str_replace('"', '""', $roleApplicatif) . '"';
            DB::statement('REVOKE UPDATE, DELETE, TRUNCATE ON public.envoi_journal_commandes FROM ' . $role);
            DB::statement('GRANT SELECT, INSERT ON public.envoi_journal_commandes TO ' . $role);
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE public.envoi_journal_commandes_id_seq TO ' . $role);
        }
    }

    /** Retour arrière INERTE : la protection « ajout seul » et les lignes sont conservées. */
    public function down(): void
    {
        // Volontairement vide (aucun effacement, aucune protection retirée).
    }
};
