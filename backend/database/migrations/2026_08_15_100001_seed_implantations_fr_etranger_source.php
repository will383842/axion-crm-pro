<?php

use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sème la nouvelle source `implantations-fr-etranger` au registre
 * `scraping_sources` (campagne « entreprises françaises implantées en
 * Roumanie », extensible à d'autres pays).
 *
 * Même mécanique que `2026_08_14_000006` : les seeders ne tournent pas au
 * déploiement (l'entrypoint ne fait que `migrate deploy`), donc toute
 * nouvelle entrée du registre passe par une migration qui rejoue le seeder —
 * idempotent (upsert par slug), ne réactive jamais un kill-switch posé en
 * prod (`enabled` absent des colonnes mises à jour).
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ScrapingSourcesSeeder)->run();
    }

    public function down(): void
    {
        // On coupe la source plutôt que de la supprimer : des `scraper_runs`
        // et des tags `src:scraping-implantations-fr-etranger` peuvent déjà y
        // référer — la provenance d'une fiche ne se réécrit pas.
        DB::table('scraping_sources')
            ->where('slug', 'implantations-fr-etranger')
            ->update(['enabled' => false, 'updated_at' => now()]);
    }
};
