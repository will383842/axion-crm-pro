<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `audit_logs.prev_hash` : la valeur par défaut disait `'GENESIS'`, le code
 * écrit 64 zéros.
 *
 * Découvert le 2026-08-16 en réparant la chaîne de hachage du journal d'audit.
 * Les deux sites du service (`record()` et `verifyChain()`) s'accordent depuis
 * toujours sur `repeat('0', 64)` ; seule la contrainte de colonne portait
 * encore l'ancienne convention.
 *
 * 🔴 Pourquoi ce n'est pas cosmétique : un INSERT qui omet `prev_hash` — un
 * import, une reprise manuelle, un script de secours — créait silencieusement
 * une ligne dont le maillon est `'GENESIS'`. `verifyChain()` la déclarerait
 * rompue, sans qu'aucune falsification n'ait eu lieu. Une alerte d'intégrité
 * qui crie au loup finit par n'être plus lue, et c'est précisément à ce
 * moment-là qu'une vraie falsification passerait.
 *
 * On ne réécrit AUCUNE ligne existante : les 33 lignes de production portent
 * déjà un `prev_hash` calculé par le code. Toucher à un journal d'audit
 * a posteriori serait exactement ce que ce journal existe pour empêcher.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE audit_logs ALTER COLUMN prev_hash SET DEFAULT repeat('0', 64)");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE audit_logs ALTER COLUMN prev_hash SET DEFAULT 'GENESIS'");
    }
};
