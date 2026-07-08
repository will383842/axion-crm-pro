<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Passe 3 du pipeline d'enrichissement : RE-VALIDATION des sites web déjà trouvés.
 *
 * Ajoute `website_revalidated_at` : horodatage du dernier re-test d'un site
 * `website_status = 'found'`. NULL = jamais re-validé → à traiter par la passe 3.
 *
 * Index PARTIEL sur les lignes à re-valider (found + jamais revalidées) : la
 * boucle reprenable de la commande `prospection:find-websites --revalidate` cible
 * exactement ces lignes ; l'index reste minuscule et ne pèse pas sur les millions
 * d'autres entreprises.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS website_revalidated_at TIMESTAMPTZ;');
        DB::statement("CREATE INDEX IF NOT EXISTS idx_companies_revalidate ON companies (website_status) WHERE website_status = 'found' AND website_revalidated_at IS NULL;");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_companies_revalidate;');
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS website_revalidated_at;');
    }
};
