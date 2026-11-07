<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Envoi K1 (ADR K0) — AJOUTS SEULS ; down() INERTE.
 *  1. `commande_id` UNIQUE PAR ESPACE : (workspace_id, commande_id) remplace l'ancien index global
 *     (commande_id) de la migration 000104 (un simple index : aucune ligne n'est touchée).
 *  2. envoi_serveurs.url_base : CHECK ^https:// (NOT VALID : les lignes existantes ne sont pas réécrites ;
 *     le contrôle vaut pour tout ajout ou changement). L'adresse effective vient de la configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE UNIQUE INDEX IF NOT EXISTS envoi_journal_commandes_ws_commande_id_uniq
                ON envoi_journal_commandes (workspace_id, commande_id);
            DROP INDEX IF EXISTS envoi_journal_commandes_commande_id_uniq;
            DO $$
            BEGIN
                IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'envoi_serveurs_url_base_https') THEN
                    ALTER TABLE envoi_serveurs ADD CONSTRAINT envoi_serveurs_url_base_https CHECK (url_base ~ '^https://') NOT VALID;
                END IF;
            END
            $$;
        SQL);
    }

    /** Retour arrière INERTE : ni index ni contrainte ni ligne retirés. */
    public function down(): void
    {
        // Volontairement vide.
    }
};
