<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 — curseur par serveur (dernier rang contigu reçu). AJOUT SEUL (CREATE ... IF NOT EXISTS) ; down() INERTE : ni table
 * ni ligne n'est retirée. RLS par workspace, même patron que envoi_temoins.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_curseurs_serveur (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
                source       TEXT NOT NULL,
                dernier_seq  BIGINT NOT NULL DEFAULT 0,
                mis_a_jour_le TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, source)
            );
            ALTER TABLE envoi_curseurs_serveur ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_curseurs_serveur FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_curseurs_serveur_workspace_isolation ON envoi_curseurs_serveur;
            CREATE POLICY envoi_curseurs_serveur_workspace_isolation ON envoi_curseurs_serveur FOR ALL
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
