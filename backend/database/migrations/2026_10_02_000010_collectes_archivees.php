<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * COLLECTES ARCHIVÉES (lot 3, audit visuel du 2026-10-02).
 *
 * L'écran « Collectes » (`/campaigns`) montrait en tête deux collectes de
 * TEST du 17 mai 2026 (« Test Paris INSEE », annulée ; « TEST Grenoble
 * INSEE », terminée). Interdiction de supprimer quoi que ce soit : on
 * ARCHIVE — on masque de la vue par défaut, réversible, sans toucher aux
 * fiches ni aux journaux de collecte rattachés.
 *
 * 1. `scraping_campaigns.archived_at` (nullable) : le statut métier
 *    (`status`) est contraint par un CHECK et lu par les traitements ; un
 *    horodatage à part n'interfère avec aucun d'eux. Action console :
 *    `POST /campaigns/{id}/archive` et `/unarchive`.
 *
 * 2. Reprise de données SÛRE : sont archivées les seules collectes dont le
 *    nom COMMENCE par le mot « test » (insensible à la casse) ET qui sont
 *    TERMINÉES (`completed`, `cancelled`, `failed`) — jamais une collecte en
 *    cours, en pause ou programmée. Seule colonne écrite : `archived_at`.
 *    Annulation : `UPDATE scraping_campaigns SET archived_at = NULL WHERE …`
 *    ou le bouton « Désarchiver ». La migration tourne sous le rôle
 *    propriétaire (`--database=pgsql_owner`), que la RLS ne filtre pas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('scraping_campaigns')) {
            return;
        }

        DB::statement('ALTER TABLE scraping_campaigns ADD COLUMN IF NOT EXISTS archived_at timestamptz NULL');

        DB::statement(<<<'SQL'
            UPDATE scraping_campaigns
               SET archived_at = now()
             WHERE archived_at IS NULL
               AND deleted_at IS NULL
               AND name ~* '^\s*test\M'
               AND status IN ('completed', 'cancelled', 'failed')
        SQL);
    }

    /**
     * Retour arrière : retire la colonne `archived_at`. Aucune donnée métier
     * n'est perdue — la colonne ne porte QUE le masquage ; les collectes,
     * leurs fiches et leurs journaux ne sont jamais touchés par `up()`. Les
     * collectes archivées redeviennent simplement visibles dans la liste.
     */
    public function down(): void
    {
        if (Schema::hasTable('scraping_campaigns')) {
            DB::statement('ALTER TABLE scraping_campaigns DROP COLUMN IF EXISTS archived_at');
        }
    }
};
