<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R12 — le registre des oppositions doit dire vrai : un STOP classé par un humain sur une réponse a le canal `reponse`
 * (et non `page`, qui dit « lien de désinscription cliqué »). Élargit la liste fermée du canal ; aucune ligne relue ni modifiée.
 * AJOUT SEUL : la contrainte est reposée NOT VALID (les lignes existantes ne sont pas réécrites). `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_oppositions DROP CONSTRAINT IF EXISTS envoi_oppositions_canal_check;
            ALTER TABLE envoi_oppositions ADD CONSTRAINT envoi_oppositions_canal_check CHECK (canal IN ('page', 'one_click', 'reponse')) NOT VALID;
        SQL);
    }

    public function down(): void {}
};
