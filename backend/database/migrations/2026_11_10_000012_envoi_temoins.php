<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L0 — témoins autorisés en mode témoins. AJOUT SEUL.
 * Jamais d'adresse en clair : `empreinte` = HMAC-SHA256 (clé d'application
 * `envoi.cle_empreinte`, hors dépôt) de l'adresse normalisée (minuscules, sans
 * espaces). `libelle` est un texte libre d'affichage (« Gmail perso »), jamais l'adresse.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS envoi_temoins (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id),
                empreinte    TEXT NOT NULL CHECK (empreinte ~ '^[0-9a-f]{64}$'),
                libelle      TEXT NOT NULL DEFAULT '',
                cree_le      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_temoins_ws_empreinte ON envoi_temoins (workspace_id, empreinte);
            ALTER TABLE envoi_temoins ENABLE ROW LEVEL SECURITY;
            ALTER TABLE envoi_temoins FORCE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS envoi_temoins_workspace_isolation ON envoi_temoins;
            CREATE POLICY envoi_temoins_workspace_isolation ON envoi_temoins FOR ALL
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
                IF EXISTS (SELECT 1 FROM envoi_temoins) THEN
                    RAISE EXCEPTION 'envoi_temoins contient des lignes : retour refusé (aucun effacement)';
                END IF;
            END $$;
            DROP TABLE IF EXISTS envoi_temoins;
        SQL);
    }
};
