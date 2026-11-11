<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi L8-F03 — fournisseur d'un témoin. AJOUT SEUL d'une colonne NULLABLE sur `envoi_temoins`
 * (aucune donnée touchée, aucun DROP/DELETE). Valeurs permises : les 6 fournisseurs témoins (A10).
 * Jamais d'adresse : la ligne garde l'empreinte HMAC et un libellé libre. `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_temoins
                ADD COLUMN IF NOT EXISTS fournisseur TEXT NULL;
            ALTER TABLE envoi_temoins DROP CONSTRAINT IF EXISTS envoi_temoins_fournisseur_valide;
            ALTER TABLE envoi_temoins
                ADD CONSTRAINT envoi_temoins_fournisseur_valide
                CHECK (fournisseur IS NULL OR fournisseur IN ('gmail', 'google_workspace', 'outlook_com', 'microsoft_365', 'yahoo', 'orange'));
        SQL);
    }

    /** Retour arrière INERTE : aucune colonne retirée. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
