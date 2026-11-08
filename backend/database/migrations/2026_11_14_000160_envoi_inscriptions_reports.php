<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L1 (M2) — compteur de reports consécutifs d'une inscription (refus passagers de la porte).
 * ADDITIF SEULEMENT : une colonne, valeur 0 pour les lignes existantes. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_inscriptions ADD COLUMN IF NOT EXISTS reports SMALLINT NOT NULL DEFAULT 0');
    }

    /** Retour arrière INERTE : la colonne est conservée. */
    public function down(): void
    {
        DB::statement("COMMENT ON TABLE envoi_inscriptions IS 'Envoi P0 L1 : colonne reports conservee au retour arriere.'");
    }
};
