<?php

use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * JOIGNABILITÉ — colonnes dérivées sur `companies` et `contacts` (chantier D).
 *
 * Will veut distinguer, pour ses campagnes, ce qui est joignable par e-mail de
 * ce qui ne l'est pas — SANS rien séparer physiquement ni rien supprimer.
 * L'état est CALCULÉ (`App\Crm\Joignabilite\Joignabilite`) et rangé dans une
 * colonne, recalculée par `crm:joignabilite:calculer` et, pour les fiches
 * qu'elle touche, par `crm:emails:verifier`.
 *
 * Pourquoi une COLONNE, et pas une vue ni des étiquettes :
 *  - une VUE ne peut pas le calculer : « adresse vérifiée valide » se lit dans
 *    la fiche de vérification, liée à l'adresse par un HMAC dont la clé dérive
 *    de `APP_KEY` (`VerificationEmail::statutDe`) — la base ne la connaît pas ;
 *  - des ÉTIQUETTES poseraient 4,3 M de liens `company_tag` (plus 1,3 M de
 *    personnes, qui n'ont pas d'étiquettes du tout), et tomberaient sous la
 *    règle « aucune synchro destructive des étiquettes » : un état qui CHANGE
 *    exige de retirer l'ancien lien ;
 *  - une colonne se filtre comme `entity_nature` : champ d'audience, filtre de
 *    liste, index composé avec l'espace.
 *
 * NULL = « pas encore calculé ». Colonnes NULLABLES sans défaut : ajoutées sans
 * réécrire les tables. Les CHECK sont posés `NOT VALID` (respectés par toute
 * écriture nouvelle) ; la migration suivante les valide hors transaction et
 * construit les index `CONCURRENTLY`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        $valeurs = Taxonomy::sqlList(Joignabilite::ETATS);

        foreach (['companies', 'contacts'] as $table) {
            DB::statement("ALTER TABLE {$table} ADD COLUMN IF NOT EXISTS joignabilite TEXT");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_joignabilite_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_joignabilite_check CHECK (joignabilite IS NULL OR joignabilite IN ({$valeurs})) NOT VALID");
            DB::statement("COMMENT ON COLUMN {$table}.joignabilite IS 'État de joignabilité CALCULÉ (crm:joignabilite:calculer). NULL = pas encore calculé. Une adresse invalide reste sur la fiche : elle est seulement exclue des envois.'");
        }
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        foreach (['companies', 'contacts'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_joignabilite_check");
            DB::statement("ALTER TABLE {$table} DROP COLUMN IF EXISTS joignabilite");
        }
    }
};
