<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi P0 L7 (réserve) : `envoi_seuils.valeur` est NUMERIC, qui accepte 'NaN' et 'Infinity'.
 * Un NaN fausse toutes les comparaisons (il serait « plus grand » que tout en SQL) : on le refuse.
 * AJOUT SEUL : contrainte NOT VALID (ne s'applique qu'aux écritures futures, ne lit ni ne supprime
 * aucune ligne existante). down() retire la contrainte seulement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_seuils_valeur_finie') THEN
                    ALTER TABLE envoi_seuils ADD CONSTRAINT envoi_seuils_valeur_finie
                        CHECK (valeur NOT IN ('NaN'::numeric, 'Infinity'::numeric, '-Infinity'::numeric)) NOT VALID;
                END IF;
            END $$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE envoi_seuils DROP CONSTRAINT IF EXISTS envoi_seuils_valeur_finie');
    }
};
