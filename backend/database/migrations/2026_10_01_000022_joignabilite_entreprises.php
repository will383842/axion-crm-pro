<?php

use App\Crm\Joignabilite\Joignabilite;
use App\Crm\Taxonomy;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * JOIGNABILITÉ — colonne dérivée sur `companies` (chantier D). `contacts` :
 * migration suivante (UNE table par migration : chaque `ALTER TABLE` attend
 * son verrou exclusif seul, sous `lock_timeout` de 5 s — relecture R6).
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
 * NULL = « pas encore calculé ». Colonne NULLABLE sans défaut : ajoutée sans
 * réécrire la table. Le CHECK est posé `NOT VALID` : respecté par toute
 * écriture nouvelle, sans relire les 4,3 M de lignes sous verrou.
 *
 * ⚠️ Le `VALIDATE CONSTRAINT` est VOLONTAIREMENT HORS du déploiement : la
 * colonne naît entièrement NULL (ce que le CHECK admet) et seul le code de
 * `Joignabilite` l'écrit, sous le CHECK. Le valider ne ferait que marquer au
 * catalogue ce qui est déjà vrai, au prix d'une relecture complète de la
 * table pendant le déploiement. S'il est voulu, à la main, hors heures :
 * `ALTER TABLE companies VALIDATE CONSTRAINT companies_joignabilite_check;`
 * (verrou `SHARE UPDATE EXCLUSIVE` : lectures et écritures continuent).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        $valeurs = Taxonomy::sqlList(Joignabilite::ETATS);
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS joignabilite TEXT');
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_joignabilite_check');
        DB::statement("ALTER TABLE companies ADD CONSTRAINT companies_joignabilite_check CHECK (joignabilite IS NULL OR joignabilite IN ({$valeurs})) NOT VALID");
        DB::statement("COMMENT ON COLUMN companies.joignabilite IS 'État de joignabilité CALCULÉ (crm:joignabilite:calculer). NULL = pas encore calculé. Une adresse invalide reste sur la fiche : elle est seulement exclue des envois.'");
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '5s'");
        DB::statement('ALTER TABLE companies DROP CONSTRAINT IF EXISTS companies_joignabilite_check');
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS joignabilite');
    }
};
