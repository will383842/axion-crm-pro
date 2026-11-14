<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi R16 — une seule ligne par jour et par fournisseur pour la relève automatique (auteur `releve_auto`) :
 * deux relèves le même jour n'écrivent qu'une ligne (rejeu sans effet). Index UNIQUE PARTIEL : la saisie manuelle de F15
 * (auteurs libres, « corriger = nouvelle ligne ») n'est pas touchée. Ajout d'index seul ; down() inerte.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_temoins_journees_releve_auto_unique
                ON envoi_temoins_journees (workspace_id, jour, fournisseur, auteur) WHERE auteur = 'releve_auto';
        SQL);
    }

    public function down(): void {}
};
