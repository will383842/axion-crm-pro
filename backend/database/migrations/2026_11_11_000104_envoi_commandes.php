<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 — journal des commande_id envoyés au serveur (unique). AJOUT SEUL (CREATE ... IF NOT EXISTS) ; down() INERTE : ni table
 * ni ligne n'est retirée. RLS par workspace, même patron que envoi_temoins.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_commandes (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
                commande_id  TEXT NOT NULL UNIQUE,
                commande     TEXT NOT NULL,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            ALTER TABLE envoi_commandes ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_commandes FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_commandes_workspace_isolation ON envoi_commandes;
            CREATE POLICY envoi_commandes_workspace_isolation ON envoi_commandes FOR ALL
                USING (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''))
                WITH CHECK (workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), ''));
        SQL);
    }

    /** Retour arrière INERTE : aucune table ni ligne retirée. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
