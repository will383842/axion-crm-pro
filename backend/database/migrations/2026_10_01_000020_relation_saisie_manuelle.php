<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * STATUT DE LA RELATION — la marque « posée à la main » (chantier B, 2026-10-01).
 *
 * `companies.relation_type` et `companies.lifecycle_stage` valent `prospect` /
 * `nouveau` sur les 4,3 M de fiches : on ne distingue pas un client. Deux
 * chemins les écriront désormais :
 *
 *  - `crm:relations:importer` (fichier du site : clients, contacts, RDV) —
 *    promotion SEULEMENT, jamais de recul ;
 *  - la fiche entreprise de la console (`PUT /companies/{id}/relation`),
 *    décision humaine, auditée.
 *
 * Une relation posée à la main n'est JAMAIS écrasée par l'import. Pour le
 * savoir sans relire l'historique, la saisie manuelle pose cette date. Elle
 * n'est effacée par aucun chemin : la retirer est une décision (SQL à la main,
 * tracé), pas un effet de bord.
 *
 * Colonne NULLABLE sans défaut : ajoutée sans réécrire la table (PostgreSQL
 * ≥ 11), sous `lock_timeout` pour ne pas faire la queue derrière une requête
 * longue.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::statement('ALTER TABLE companies ADD COLUMN IF NOT EXISTS relation_saisie_manuelle_at TIMESTAMPTZ');
        DB::statement("COMMENT ON COLUMN companies.relation_saisie_manuelle_at IS 'Date de la dernière saisie MANUELLE (console) du type de relation / de l''étape. Non nulle : crm:relations:importer ne touche plus ces deux colonnes.'");
    }

    public function down(): void
    {
        DB::statement("SET LOCAL lock_timeout = '30s'");
        DB::statement('ALTER TABLE companies DROP COLUMN IF EXISTS relation_saisie_manuelle_at');
    }
};
