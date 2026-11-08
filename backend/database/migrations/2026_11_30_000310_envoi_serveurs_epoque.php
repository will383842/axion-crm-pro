<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 (suite K2) — mémoire de l'ÉPOQUE de la passerelle, par serveur. AJOUT SEUL de trois colonnes NULLABLES sur
 * `envoi_serveurs` (aucune donnée touchée, aucun DROP/DELETE) :
 *  - epoque_passerelle : époque reçue au premier tirage, renvoyée (paramètre `epoque`) à chaque tirage suivant ;
 *  - tirage_bloque_le / tirage_bloque_motif : désynchronisation constatée => tirage arrêté pour ce serveur jusqu'à
 *    intervention humaine (les COMMANDES pause/retrait restent possibles). `down()` INERTE.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_serveurs
                ADD COLUMN IF NOT EXISTS epoque_passerelle   TEXT NULL CHECK (epoque_passerelle ~ '^[0-9a-f]{16}$'),
                ADD COLUMN IF NOT EXISTS tirage_bloque_le    TIMESTAMPTZ NULL,
                ADD COLUMN IF NOT EXISTS tirage_bloque_motif TEXT NULL;
        SQL);
    }

    /** Retour arrière INERTE : aucune colonne retirée. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
