<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * VALIDATION de `companies_archive_reason_check` (avis exactitude #313, R9).
 *
 * La migration `2026_10_04_000010` a reposé la contrainte `NOT VALID` pour y
 * ajouter `non_diffusible` sans bloquer la table. La contrainte d'origine
 * était VALIDÉE ; sans ce pas, elle perdrait ce statut pour toujours.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `VALIDATE CONSTRAINT` prend un verrou SHARE UPDATE EXCLUSIVE : lectures et
 * écritures continuent pendant la vérification. Une seule lecture de la table
 * (≈ 9,3 Go), sans réécriture ni WAL notable. `lock_timeout` borne l'attente
 * du verrou ; hors transaction, comme les index `CONCURRENTLY` du dépôt.
 * Sans effet si la contrainte est déjà validée (rejouable).
 *
 * PUREMENT ADDITIVE : aucune ligne n'est réécrite. `down()` ne fait rien — une
 * contrainte validée ne se « dévalide » pas, et rien n'est à défaire.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }
        $aValider = DB::selectOne(
            "SELECT 1 AS a FROM pg_constraint
              WHERE conrelid = 'companies'::regclass
                AND conname = 'companies_archive_reason_check'
                AND NOT convalidated",
        );
        if ($aValider === null) {
            return;
        }
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('ALTER TABLE companies VALIDATE CONSTRAINT companies_archive_reason_check');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        // Rien à défaire : la validation ne modifie aucune ligne.
    }
};
