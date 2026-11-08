<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 — serveurs d'envoi connus (source => URL). AJOUT SEUL (CREATE ... IF NOT EXISTS) ; down() INERTE : ni table
 * ni ligne n'est retirée. RLS par workspace, même patron que envoi_temoins.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_serveurs (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
                source       TEXT NOT NULL CHECK (source ~ '^[a-z0-9_-]{1,64}$'),
                url_base     TEXT NOT NULL CHECK (url_base ~ '^https://'), -- indicatif : l'adresse effective vient de la configuration
                actif        BOOLEAN NOT NULL DEFAULT true,
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, source)
            );
            ALTER TABLE envoi_serveurs ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_serveurs FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_serveurs_workspace_isolation ON envoi_serveurs;
            CREATE POLICY envoi_serveurs_workspace_isolation ON envoi_serveurs FOR ALL
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
