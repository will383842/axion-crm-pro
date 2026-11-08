<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 (ADR K0) — pas de second journal : `envoi_journal_commandes` reçoit `commande_id` (UUID, CHECK de
 * format) et `source` (serveur visé, pour le rejeu), nullables (les lignes P0 n'en ont pas). AJOUTS SEULS ;
 * down() INERTE. Le trigger « ajout seul » et la RLS existants s'appliquent inchangés.
 *  - UNIQUE PAR ESPACE (workspace_id, commande_id) : l'intention.
 *  - UNIQUE PARTIEL (workspace_id, cle_idempotence) WHERE commande = 'acquittement-serveur' : un seul acquittement.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            ALTER TABLE envoi_journal_commandes
                ADD COLUMN IF NOT EXISTS commande_id TEXT
                    CHECK (commande_id IS NULL OR commande_id ~ '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$');
            ALTER TABLE envoi_journal_commandes
                ADD COLUMN IF NOT EXISTS source TEXT
                    CHECK (source IS NULL OR source ~ '^[a-z0-9_-]{1,64}$');
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_ws_commande_id_uniq
                ON envoi_journal_commandes (workspace_id, commande_id);
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_ack_uniq
                ON envoi_journal_commandes (workspace_id, cle_idempotence)
                WHERE commande = 'acquittement-serveur';
        SQL);
    }

    /** Retour arrière INERTE : ni colonne, ni index, ni ligne retirés. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
