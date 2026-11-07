<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 — état monotone par message (le rang ne fait que monter). AJOUT SEUL (CREATE ... IF NOT EXISTS) ; down() INERTE : ni table
 * ni ligne n'est retirée. RLS par workspace, même patron que envoi_temoins.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_etats_message (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
                source       TEXT NOT NULL,
                id_injection TEXT NOT NULL,
                etat         TEXT NOT NULL,
                rang         SMALLINT NOT NULL,
                mis_a_jour_le TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, source, id_injection)
            );
            ALTER TABLE envoi_etats_message ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_etats_message FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_etats_message_workspace_isolation ON envoi_etats_message;
            CREATE POLICY envoi_etats_message_workspace_isolation ON envoi_etats_message FOR ALL
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
