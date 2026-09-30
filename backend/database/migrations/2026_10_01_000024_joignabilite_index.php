<?php

use App\Console\Commands\CrmRelationsImporter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX des chantiers B et D, construits `CONCURRENTLY` (hors transaction,
 * d'où `$withinTransaction = false`) : aucune écriture n'est bloquée pendant
 * la construction. Un index laissé INVALIDE par une construction interrompue
 * est retiré puis reconstruit. Même patron que `2026_09_29_000002`.
 *
 *  - `(workspace_id, joignabilite)` sur `companies` et `contacts` : le filtre
 *    de liste (`filter[joignabilite]`) et le champ d'audience ;
 *  - `domaine du site` sur `companies` : l'étape « domaine » de
 *    `crm:relations:importer` compare le domaine de l'e-mail à celui du site
 *    (`CrmRelationsImporter::expressionDomaineDuSite`, écrite UNE fois et
 *    reprise ici à l'identique — sans quoi l'index ne servirait pas). Sans
 *    lui, chaque paquet relirait les 4,3 M de fiches (relecture R8).
 *
 * Pas de `VALIDATE CONSTRAINT` ici : voir `2026_10_01_000022`.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @return array<string, string> nom → définition (après `ON`) */
    public static function index(): array
    {
        return [
            'idx_companies_ws_joignabilite' => 'companies (workspace_id, joignabilite) WHERE joignabilite IS NOT NULL',
            'idx_contacts_ws_joignabilite' => 'contacts (workspace_id, joignabilite) WHERE joignabilite IS NOT NULL',
            'idx_companies_domaine_site' => 'companies ((' . CrmRelationsImporter::expressionDomaineDuSite('website') . ')) WHERE website IS NOT NULL',
        ];
    }

    public function up(): void
    {
        if (! Schema::hasTable('companies')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
            foreach (self::index() as $nom => $definition) {
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
        foreach (array_keys(self::index()) as $nom) {
            DB::statement("DROP INDEX CONCURRENTLY IF EXISTS {$nom}");
        }
    }
};
