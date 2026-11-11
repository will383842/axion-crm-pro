<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * R07 (feu vert A12) : flux d'un domaine d'envoi, « prospection » ou « lettre ».
 * AJOUT SEUL : une colonne NULL (l'ancien code l'ignore), une contrainte NOT VALID ; aucune ligne relue ni modifiée.
 * Tant qu'un domaine a `flux` NULL, le point auto.separation_lettre_prospection reste à faire. `down()` inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE envoi_domaines ADD COLUMN IF NOT EXISTS flux TEXT NULL');
        DB::statement('ALTER TABLE envoi_domaines DROP CONSTRAINT IF EXISTS envoi_domaines_flux_valide');
        DB::statement("ALTER TABLE envoi_domaines ADD CONSTRAINT envoi_domaines_flux_valide CHECK (flux IS NULL OR flux IN ('prospection', 'lettre')) NOT VALID");
        DB::statement("COMMENT ON COLUMN envoi_domaines.flux IS 'R07 : flux du domaine (prospection ou lettre), NULL = non étiqueté.'");
    }

    /** Retour arrière INERTE : la colonne et la contrainte sont conservées. */
    public function down(): void
    {
        DB::statement("COMMENT ON COLUMN envoi_domaines.flux IS 'R07 : conservee au retour arriere.'");
    }
};
