<?php

use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SOURCE `gofab-2026` AU REGISTRE (2026-09-29).
 *
 * Will veut retrouver dans le CRM les participants du salon GOFAB 2026
 * (visiteurs et exposants) : compléter les fiches d'entreprises existantes,
 * ajouter les contacts — même quand la fiche en porte déjà — et savoir, sur
 * chaque fiche, que l'information vient de GOFAB.
 *
 * Rien de neuf à coder : la porte commune (`scraping:ingest-file`) sait déjà
 * tout faire — rattachement par SIREN, écriture backfill-only, dédup des
 * personnes, tag de provenance `src:scraping-gofab-2026` sur l'entreprise,
 * `gofab-2026` cumulé dans `contacts.sources`, activité `scraped` dans la
 * timeline. Il ne manque que l'entrée au REGISTRE, car une source inconnue est
 * refusée (422) et les seeders ne tournent pas au déploiement.
 *
 * La fiche n'est PAS protégée (`FichesProtegees`) : ce sont des entreprises
 * ordinaires, pas des organisateurs d'événements.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new ScrapingSourcesSeeder)->run();
    }

    public function down(): void
    {
        // On COUPE la source, on ne la supprime pas : `scraper_runs` et les
        // `contacts.sources` la citent, et le registre ne perd jamais une ligne.
        DB::table('scraping_sources')->where('slug', 'gofab-2026')
            ->update(['enabled' => false, 'updated_at' => now()]);
    }
};
