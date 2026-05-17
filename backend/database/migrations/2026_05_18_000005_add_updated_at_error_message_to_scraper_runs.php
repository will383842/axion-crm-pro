<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 19.8 — fix scraper_runs schema pour les campagnes.
 *
 * Quand LaunchZoneScrapingJob fait ScraperRun::create(), Eloquent ajoute
 * automatiquement updated_at + created_at. La table avait été créée sans
 * updated_at (legacy schema event-sourced), ce qui plantait l'insert :
 *   SQLSTATE[42703]: column "updated_at" of relation "scraper_runs" does not exist
 *
 * On ajoute aussi error_message pour stocker l'erreur en cas de fail
 * (vue dans le Drawer de la page ScraperRuns).
 *
 * Idempotent via IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE scraper_runs
                ADD COLUMN IF NOT EXISTS updated_at TIMESTAMPTZ NOT NULL DEFAULT now();
            -- error column already exists per existing model fillable.
        SQL);
    }

    public function down(): void
    {
        // No-op : ces colonnes deviennent canoniques. Drop manuel si vraiment nécessaire.
    }
};
