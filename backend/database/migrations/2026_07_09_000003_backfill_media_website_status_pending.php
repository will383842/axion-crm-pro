<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfill « website_status = pending » sur les médias gelés (chantier
 * durcissement audit 2026-07-09).
 *
 * Cause racine : les imports ARCOM / Wikidata (chaînes) inséraient les médias
 * SANS `website_status`, laissant la colonne à NULL. Or `media:find-websites`
 * ne prend en charge que les lignes `website_status = 'pending'` → ~2 722 médias
 * ARCOM/Wikidata sans site restaient invisibles du moteur de découverte.
 *
 * Ce backfill débloque le stock existant en prod. Les inserts sont corrigés en
 * amont (ImportMediaFromArcom + ImportMediaEmissionsFromWikidata posent désormais
 * pending), donc cette migration ne concerne QUE l'existant.
 *
 * Périmètre volontairement conservateur :
 *   - `website_status IS NULL`            → ne réécrit jamais found/not_found/exhausted
 *   - `media_type <> 'tv_emission'`       → les émissions HÉRITENT d'une chaîne,
 *                                           on ne leur devine pas de domaine propre
 *   - `website IS NULL OR website = ''`   → n'« ouvre » pas les médias déjà pourvus
 *   - `company_id IS NULL`                → un média rattaché à une company hérite
 *                                           du site de l'entreprise, pas de recherche
 *
 * Migration transactionnelle normale. Idempotente (le filtre NULL fait qu'un
 * second run ne touche plus rien).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
            UPDATE media
               SET website_status = 'pending'
             WHERE website_status IS NULL
               AND media_type <> 'tv_emission'
               AND (website IS NULL OR website = '')
               AND company_id IS NULL
        SQL);
    }

    public function down(): void
    {
        // No-op : on ne remet pas volontairement des médias à NULL (perte du
        // suivi de couverture). Le backfill est sûr et idempotent.
    }
};
