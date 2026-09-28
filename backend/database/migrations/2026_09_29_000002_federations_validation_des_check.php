<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * FÉDÉRATIONS — la validation des deux CHECK reposés `NOT VALID` par la
 * migration précédente (`companies_entity_nature_check`,
 * `activities_kind_check`).
 *
 * `VALIDATE CONSTRAINT` relit la table sous un verrou `SHARE UPDATE
 * EXCLUSIVE` : lectures ET écritures continuent. Dans la transaction de la
 * migration précédente, il aurait tenu le verrou EXCLUSIF de l'`ADD
 * CONSTRAINT` pendant toute la relecture des 4,3 M de fiches. Même patron que
 * `2026_09_28_000002`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        // Hors transaction : `SET` vaut pour la session, d'où le `RESET`
        // final. Un verrou qui ne vient pas fait ÉCHOUER la migration (donc le
        // déploiement, qui le dit) au lieu de mettre la console en file.
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('ALTER TABLE companies VALIDATE CONSTRAINT companies_entity_nature_check');
            DB::statement('ALTER TABLE activities VALIDATE CONSTRAINT activities_kind_check');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        // Rien à défaire : une contrainte validée reste la même contrainte.
    }
};
