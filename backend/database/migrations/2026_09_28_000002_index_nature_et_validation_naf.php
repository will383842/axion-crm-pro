<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * RÉFÉRENTIELS UNIQUES — ce qui ne doit PAS tenir un verrou (2026-09-28).
 *
 * ── 1. L'INDEX « NATURE » ─────────────────────────────────────────────────
 *
 * Le seul index sur `entity_nature` était PARTIEL, étrangers seulement
 * (`idx_companies_workspace_country_nature … WHERE country_code <> 'FR'`) : un
 * filtre « nature = association » sur les 4,29 M de fiches françaises balayait
 * la table entière.
 *
 * La FORME, et pourquoi elle est partielle : une fois le reclassement passé,
 * ~4,29 M de fiches portent `entreprise`, ~4 000 une autre nature. Pour
 * « entreprise », un index ne sert à rien (c'est presque toute la table, le
 * balayage est le bon plan) ; pour les autres natures, c'est une aiguille dans
 * une botte de foin. L'index ne porte donc QUE les fiches d'une autre nature :
 * quelques milliers d'entrées au lieu de 4,3 M, et PostgreSQL sait qu'il sert
 * « nature = 'association' » (une égalité à une autre constante implique
 * `<> 'entreprise'`).
 *
 * `(workspace_id, entity_nature)` : c'est la forme exacte du filtre
 * `filter[entity_nature]` (CompanyQueryFilters), toujours posé sous un espace.
 *
 * Nom `idx_companies_workspace_nature_hors_entreprise` VÉRIFIÉ LIBRE le
 * 2026-09-28 dans `database/migrations/` (un `IF NOT EXISTS` sur un nom déjà
 * pris est un silence, pas une idempotence — cf. `G41-003`).
 *
 * Le secteur, lui, a déjà son index : `idx_companies_sector
 * (workspace_id, sector_main)` (migration `2026_05_18_000006`) — la région
 * aussi (`idx_companies_region`). Sa garde de plan est ajoutée avec celle de la
 * nature (`IndexesEmployesParLeProduitTest`).
 *
 * ── 2. LA VALIDATION DES CHECK DE LA MIGRATION PRÉCÉDENTE ─────────────────
 *
 * `VALIDATE CONSTRAINT` relit la table sous un verrou `SHARE UPDATE EXCLUSIVE`
 * — lectures ET écritures continuent. Dans la transaction de la migration
 * précédente, il aurait tenu le verrou EXCLUSIF de l'`ADD CONSTRAINT` pendant
 * toute la relecture des 4,3 M de lignes.
 */
return new class extends Migration
{
    /**
     * `CREATE INDEX CONCURRENTLY` est interdit dans une transaction, et Laravel
     * enveloppe les migrations par défaut. Sans `CONCURRENTLY`, la création
     * poserait un verrou `SHARE` sur `companies` : toute écriture bloquée
     * pendant la construction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::statement('ALTER TABLE companies VALIDATE CONSTRAINT companies_naf_nomenclature_check');
        DB::statement('ALTER TABLE companies VALIDATE CONSTRAINT companies_naf_rev2_check');

        // `IF NOT EXISTS` rend la migration rejouable — indispensable avec
        // `CONCURRENTLY`, qui peut échouer à mi-course en laissant un index
        // INVALIDE derrière lui (le supprimer à la main, puis rejouer).
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS idx_companies_workspace_nature_hors_entreprise '
            . "ON companies (workspace_id, entity_nature) WHERE entity_nature <> 'entreprise'",
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS idx_companies_workspace_nature_hors_entreprise');
    }
};
