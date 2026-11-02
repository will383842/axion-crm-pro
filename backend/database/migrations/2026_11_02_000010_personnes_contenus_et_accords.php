<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * PV-6 — « Contenus et accords » : cinq colonnes AJOUTÉES à `personnes`.
 *
 * PUREMENT ADDITIVE : `ADD COLUMN IF NOT EXISTS`, tout nullable, aucun défaut
 * coûteux (pas de réécriture de table), aucun DROP, aucune purge. `down()` ne
 * retire rien : on ne supprime jamais de donnée de personne.
 *
 * Ni pseudo de réseau ni texte de message : seules des listes de mots, de
 * guides, d'accords (nature, date, source, version) et la date d'opposition.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS origine_contenu jsonb NULL');
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS guides_recus jsonb NULL');
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS consentements jsonb NULL');
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS opposition_at timestamptz NULL');
        DB::statement('ALTER TABLE personnes ADD COLUMN IF NOT EXISTS accords_at timestamptz NULL');

        DB::statement("COMMENT ON COLUMN personnes.origine_contenu IS 'PV-6 : mot, reseau, guide, date. Jamais le pseudo ni le texte du message.'");
        DB::statement("COMMENT ON COLUMN personnes.opposition_at IS 'PV-6 : posee, jamais levee par la synchro. Met la fiche a l ecart de tout envoi.'");
        DB::statement("COMMENT ON COLUMN personnes.accords_at IS 'PV-6 : instant du dernier evenement qui a porte l origine (le plus recent gagne).'");
    }

    public function down(): void
    {
        // Volontairement vide : aucune suppression de donnée de personne.
    }
};
