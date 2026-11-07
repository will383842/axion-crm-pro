<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L3 — domaines d'envoi : (g) nom en minuscules imposé par contrainte,
 * (h) relevé « liste noire » (la vérification réelle viendra plus tard).
 * ADDITIF SEULEMENT : une colonne, une contrainte NOT VALID (les lignes déjà
 * présentes ne sont ni relues ni modifiées). `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_domaines ADD COLUMN IF NOT EXISTS liste_noire BOOLEAN NOT NULL DEFAULT false');
        DB::statement('ALTER TABLE envoi_domaines DROP CONSTRAINT IF EXISTS envoi_domaines_nom_minuscules');
        DB::statement('ALTER TABLE envoi_domaines ADD CONSTRAINT envoi_domaines_nom_minuscules CHECK (nom = lower(nom)) NOT VALID');
        DB::statement("COMMENT ON COLUMN envoi_domaines.liste_noire IS 'Releve : domaine d envoi present sur une liste noire (spec 26 6.2). Verification reelle a venir.'");
    }

    /** Retour arrière INERTE : colonne et contrainte sont conservées. */
    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN envoi_domaines.liste_noire IS 'Envoi P0 L3 : conservee au retour arriere.'");
    }
};
