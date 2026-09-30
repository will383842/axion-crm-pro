<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * LISTES MANUELLES — l'index qui sert le rapprochement d'un fichier par
 * ADRESSE GÉNÉRIQUE (2026-09-30).
 *
 * Importer une liste d'adresses (« Invités salon GOFAB ») rapproche chaque
 * ligne d'une personne (`contacts.email`, CITEXT déjà indexé) ou, à défaut,
 * d'une organisation par son adresse générique. `companies.email_generic` est
 * un VARCHAR sans index : chaque paquet de rapprochement lirait les 4,29 M de
 * fiches. La recherche porte sur l'adresse NORMALISÉE (`lower(btrim(…))`),
 * celle de `QualificationEmail::normaliser()` : c'est cette expression, et
 * elle seule, qu'indexe `idx_companies_email_generic_norm`.
 *
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée pendant la
 * construction. Un index laissé INVALIDE par une construction interrompue est
 * retiré puis reconstruit (patron de `2026_10_01_000002`). Nom VÉRIFIÉ LIBRE
 * le 2026-09-30.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public const NOM = 'idx_companies_email_generic_norm';

    public const DEFINITION = "companies (workspace_id, lower(btrim(email_generic))) WHERE email_generic IS NOT NULL AND email_generic <> ''";

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
            $invalide = DB::selectOne(
                'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                [self::NOM],
            );
            if ($invalide !== null && (bool) $invalide->invalide) {
                DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
            }
            DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS ' . self::NOM . ' ON ' . self::DEFINITION);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS ' . self::NOM);
    }
};
