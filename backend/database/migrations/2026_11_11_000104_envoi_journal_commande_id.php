<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 (ADR K0) — pas de second journal : la table `envoi_journal_commandes` reçoit une colonne
 * `commande_id` UNIQUE (nullable : les lignes P0 n'en ont pas). AJOUT SEUL ; down() INERTE.
 * Le trigger « ajout seul » et la RLS existants s'appliquent inchangés.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_journal_commandes ADD COLUMN IF NOT EXISTS commande_id TEXT;
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_commande_id_uniq
                ON envoi_journal_commandes (commande_id);
        SQL);
    }

    /** Retour arrière INERTE : ni colonne ni ligne retirée. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
