<?php

use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * JOIGNABILITÉ — colonne dérivée sur `contacts` (chantier D). Même contrat que
 * la migration précédente pour `companies` (colonne nullable, CHECK
 * `NOT VALID`, `VALIDATE` hors déploiement, `lock_timeout` 5 s).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        $valeurs = Taxonomy::sqlList(Joignabilite::ETATS);
        DB::statement('ALTER TABLE contacts ADD COLUMN IF NOT EXISTS joignabilite TEXT');
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_joignabilite_check');
        DB::statement("ALTER TABLE contacts ADD CONSTRAINT contacts_joignabilite_check CHECK (joignabilite IS NULL OR joignabilite IN ({$valeurs})) NOT VALID");
        DB::statement("COMMENT ON COLUMN contacts.joignabilite IS 'État de joignabilité CALCULÉ (crm:joignabilite:calculer). NULL = pas encore calculé. Une adresse invalide reste sur la fiche : elle est seulement exclue des envois.'");
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE contacts DROP CONSTRAINT IF EXISTS contacts_joignabilite_check');
        DB::statement('ALTER TABLE contacts DROP COLUMN IF EXISTS joignabilite');
    }
};
