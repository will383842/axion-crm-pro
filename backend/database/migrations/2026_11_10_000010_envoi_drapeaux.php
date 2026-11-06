<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L0 — drapeaux du socle local, par workspace. AJOUT SEUL.
 * `feu_vert_date` + `feu_vert_signature` : feu vert signé (utilisé par un lot
 * ultérieur). down() ne retire la table que si elle est vide.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_drapeaux (
                workspace_id       UUID PRIMARY KEY REFERENCES workspaces(id),
                tout_arreter       BOOLEAN NOT NULL DEFAULT false,
                mode_temoins       BOOLEAN NOT NULL DEFAULT true,
                feu_vert_date      DATE NULL,
                feu_vert_signature TEXT NULL,
                mis_a_jour_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            ALTER TABLE envoi_drapeaux ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_drapeaux FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_drapeaux_workspace_isolation ON envoi_drapeaux;
            CREATE POLICY envoi_drapeaux_workspace_isolation ON envoi_drapeaux FOR ALL
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
                IF EXISTS (SELECT 1 FROM envoi_drapeaux) THEN
                    RAISE EXCEPTION 'envoi_drapeaux contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_drapeaux;
            SELECT 1;
                    SQL);
    }
};
