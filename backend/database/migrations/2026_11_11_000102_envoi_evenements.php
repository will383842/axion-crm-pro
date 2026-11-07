<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 — événements reçus ; doublons écartés par (source, event_id). AJOUT SEUL (CREATE ... IF NOT EXISTS) ; down() INERTE : ni table
 * ni ligne n'est retirée. RLS par workspace, même patron que envoi_temoins.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_evenements (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
                source       TEXT NOT NULL,
                event_id     TEXT NOT NULL,
                seq          BIGINT NOT NULL,
                type         TEXT NOT NULL, -- 'ignore' pour tout type non retenu (jamais d'ouverture)
                id_injection TEXT NOT NULL,
                recu_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (source, event_id)
            );
            ALTER TABLE envoi_evenements ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_evenements FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_evenements_workspace_isolation ON envoi_evenements;
            CREATE POLICY envoi_evenements_workspace_isolation ON envoi_evenements FOR ALL
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
