<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * L3c-0 (ADR contrat v3 § 6, I2) — le MOTIF de la mise à l'écart d'une personne.
 *
 * PUREMENT ADDITIVE : une colonne facultative, aucune donnée touchée. La mise à
 * l'écart reste portée par `opposition_at` (colonne existante, inchangée) ; ceci
 * n'ajoute que la raison (`opposition`, `demande_effacement`…). Jamais de DROP.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS opposition_motif text NULL');
        DB::statement("COMMENT ON COLUMN personnes.opposition_motif IS 'L3c-0 : raison de la mise a l ecart (opposition_at). Facultatif. Aucune suppression de personne.'");
    }

    public function down(): void
    {
        // On ne retire jamais de donnée de personne : la colonne reste.
        DB::statement("COMMENT ON COLUMN personnes.opposition_motif IS 'L3c-0 : colonne conservee au retour arriere.'");
    }
};
