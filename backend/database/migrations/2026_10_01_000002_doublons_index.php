<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DOUBLONS — les trois index qui manquaient à la fusion (chantier 5).
 *
 * Une fusion rattache à la fiche gardée TOUT ce qui pointe vers la fiche
 * absorbée. Trois de ces recherches n'avaient aucun index :
 *
 *   idx_activities_sujet_fiche  activities (workspace_id, subject_id)
 *                               WHERE subject_type = 'company'
 *                               — l'historique d'une fiche (démarches,
 *                               imports, changements), écrit à chaque
 *                               ingestion : sans index, chaque fusion lirait
 *                               TOUTE la table ;
 *   idx_deals_company           deals (company_id)
 *   idx_journalists_company     journalists (company_id) WHERE company_id IS NOT NULL
 *                               — seul `journalists_workspace_idx` servait, qui lit
 *                               tout l'espace
 *
 * Toutes les autres tables rattachées ont déjà le leur (clé primaire de
 * `company_tag` et de `federations`, `idx_contacts_company`,
 * `idx_event_organizers_company`, `idx_runs_company_source_status`,
 * `idx_audience_members_company`, `media_company_idx`, `hp_company_idx`,
 * `idx_personnes_company`, `idx_federations_parent`) — `DoublonsServisParDesIndexTest`
 * le vérifie par `EXPLAIN`.
 *
 * `CONCURRENTLY`, hors transaction : aucune écriture bloquée pendant la
 * construction. Un index laissé INVALIDE par une construction interrompue est
 * retiré puis reconstruit (même patron que `2026_09_29_000002`). Noms VÉRIFIÉS
 * LIBRES le 2026-09-30.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> nom → définition (après `ON`) */
    public const INDEX = [
        'idx_activities_sujet_fiche' => "activities (workspace_id, subject_id) WHERE subject_type = 'company' AND subject_id IS NOT NULL",
        'idx_deals_company' => 'deals (company_id)',
        'idx_journalists_company' => 'journalists (company_id) WHERE company_id IS NOT NULL',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
            return;
        }

        DB::statement("SET lock_timeout = '30s'");
        try {
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
            DB::statement("DROP INDEX IF EXISTS {$nom}");
        }
    }
};
