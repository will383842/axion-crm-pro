<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX UNIQUE PARTIEL de `companies.console_client_ref` (25/10/2026).
 *
 * Un client de la console = une seule fiche du CRM, par espace : c'est la
 * première clé de rapprochement de `DossierClientIngest` (avant le SIREN), et
 * la garantie en base qu'aucune synchro ne crée deux fiches pour le même
 * client. Partiel (`IS NOT NULL`) : seules les fiches venues de la console y
 * entrent — quelques dizaines d'entrées sur ≈ 4,4 M fiches.
 *
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée. Un index laissé
 * INVALIDE par une construction interrompue est retiré puis reconstruit
 * (patron de `2026_10_06_000020`). PUREMENT ADDITIVE.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'companies_workspace_console_client_ref_key';

    public function up(): void
    {
        if (! Schema::hasTable('companies') || ! Schema::hasColumn('companies', 'console_client_ref')) {
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
            DB::statement('CREATE UNIQUE INDEX CONCURRENTLY IF NOT EXISTS ' . self::NOM
                . ' ON companies (workspace_id, console_client_ref) WHERE console_client_ref IS NOT NULL');
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
