<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Colonne dédiée `enseigne` sur companies (jusqu'ici seulement dans metadata JSONB).
 * Facilite l'affichage terrain, l'export CSV et le quality_score.
 *
 * Idempotent : ADD COLUMN IF NOT EXISTS + backfill depuis metadata->>'enseigne'.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS enseigne TEXT;');

        DB::statement(<<<'SQL'
            UPDATE companies
               SET enseigne = metadata->>'enseigne'
             WHERE enseigne IS NULL
                              AND metadata->>'enseigne' IS NOT NULL
               AND metadata->>'enseigne' <> '';
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS enseigne;');
    }
};
