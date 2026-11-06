<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L0 — journal des commandes, AJOUT SEUL : un trigger refuse UPDATE
 * et DELETE. down() ne retire que la protection (trigger), jamais la table ni ses lignes.
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
                RAISE EXCEPTION 'envoi_journal_commandes : ajout seulement (UPDATE/DELETE refusés)';
            END;
            $f$ LANGUAGE plpgsql SET search_path = pg_catalog, public;
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
        // Retour PRUDENT (REGLES-SOCLE S8 : aucun effacement de données) : on retire
        // seulement la protection « ajout seul » (trigger + fonction). La table et
        // ses lignes RESTENT ; up() repose la protection (aller-retour-aller sûr).
        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS envoi_journal_commandes_ajout_seul ON envoi_journal_commandes;
            DROP FUNCTION IF EXISTS envoi_journal_commandes_ajout_seul();
        SQL);
    }
};
