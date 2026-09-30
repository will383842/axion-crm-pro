<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * JOIGNABILITÉ — validation des CHECK et index (chantier D).
 *
 * `VALIDATE CONSTRAINT` relit la table sous `SHARE UPDATE EXCLUSIVE` :
 * lectures et écritures continuent. Les index sont construits `CONCURRENTLY`
 * (hors transaction, d'où `$withinTransaction = false`). Un index laissé
 * INVALIDE par une construction interrompue est retiré puis reconstruit.
 * Même patron que `2026_09_29_000002`.
 *
 * Les index servent les deux usages : le filtre de liste (`filter[joignabilite]`)
 * et le champ d'audience, qui filtrent tous deux DANS un espace.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> nom → définition (après `ON`) */
    public const INDEX = [
        'idx_companies_ws_joignabilite' => 'companies (workspace_id, joignabilite) WHERE joignabilite IS NOT NULL',
        'idx_contacts_ws_joignabilite' => 'contacts (workspace_id, joignabilite) WHERE joignabilite IS NOT NULL',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('ALTER TABLE companies VALIDATE CONSTRAINT companies_joignabilite_check');
            DB::statement('ALTER TABLE contacts VALIDATE CONSTRAINT contacts_joignabilite_check');

            foreach (self::INDEX as $nom => $definition) {
                $invalide = DB::selectOne(
                    'SELECT NOT i.indisvalid AS invalide FROM pg_index i JOIN pg_class c ON c.oid = i.indexrelid WHERE c.relname = ?',
                    [$nom],
                );
                if ($invalide !== null && (bool) $invalide->invalide) {
                    DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$nom}");
                }
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$nom} ON {$definition}");
            }
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::INDEX) as $nom) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$nom}");
        }
    }
};
