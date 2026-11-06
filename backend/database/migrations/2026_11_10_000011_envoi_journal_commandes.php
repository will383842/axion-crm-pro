<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L0 — journal des commandes, AJOUT SEUL : un trigger refuse UPDATE
 * et DELETE. down() ne retire la table que si elle est vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_journal_commandes (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
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
                RAISE EXCEPTION 'envoi_journal_commandes : ajout seulement (UPDATE/DELETE refusés)';
            END;
            $f$ LANGUAGE plpgsql;
            DROP TRIGGER IF EXISTS envoi_journal_commandes_ajout_seul ON envoi_journal_commandes;
            CREATE TRIGGER envoi_journal_commandes_ajout_seul
                BEFORE UPDATE OR DELETE ON envoi_journal_commandes
                FOR EACH ROW EXECUTE FUNCTION envoi_journal_commandes_ajout_seul();
            ALTER TABLE envoi_journal_commandes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_journal_commandes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_journal_commandes_workspace_isolation ON envoi_journal_commandes;
            CREATE POLICY envoi_journal_commandes_workspace_isolation ON envoi_journal_commandes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
        SQL);
    }

    public function down(): void
    {
        // Retour PRUDENT (REGLES-SOCLE S8 : aucun effacement de données) : la table
        // n'est retirée que si elle est VIDE ; sinon le retour est refusé.
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (SELECT 1 FROM envoi_journal_commandes) THEN
                    RAISE EXCEPTION 'envoi_journal_commandes contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_journal_commandes;
            DROP FUNCTION IF EXISTS envoi_journal_commandes_ajout_seul();
        SQL);
    }
};
