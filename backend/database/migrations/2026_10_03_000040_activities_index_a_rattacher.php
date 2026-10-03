<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL de la file « Personnes à rattacher » (audit UX lot 8).
 *
 * ── Pourquoi ──────────────────────────────────────────────────────────────
 * La file (`App\Crm\Console\FilesATraiter::aRattacher`) est désormais lue par
 * l'écran `/crm/arbitrage` ET par la pastille du menu, rafraîchie toutes les
 * 60 s sur tous les écrans. Ses trois conditions :
 *
 *     subject_id IS NULL
 *     AND payload -> 'pending_match' IS NOT NULL
 *     AND payload -> 'arbitrage_dismissed' IS NULL
 *
 * n'avaient aucun index : la lecture passait par `idx_activities_workspace_
 * occurred` et relisait TOUTES les activités de l'espace pour en garder une
 * poignée. Pire, sous la sécurité par espace FORCÉE (`axion_app`), l'opérateur
 * JSON `->` n'est pas « leakproof » : Postgres ne peut l'évaluer qu'après la
 * politique, ligne à ligne, sur le tas.
 *
 * Un index PARTIEL dont le prédicat reprend ces trois conditions MOT POUR MOT
 * ne porte que les lignes en attente (quelques dizaines) : le planificateur
 * reconnaît le prédicat dans la requête et n'a plus à réévaluer le JSON. Les
 * colonnes `(workspace_id, occurred_at, id)` servent en plus le tri de l'écran
 * (« les plus anciens d'abord »).
 *
 * ⚠️ Le prédicat doit rester IDENTIQUE à `FilesATraiter::aRattacher` : une
 * condition réécrite autrement (`payload ? 'pending_match'`, par exemple) ne
 * serait plus reconnue, et l'index ne servirait plus — sans aucune erreur.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `CONCURRENTLY`, hors transaction (`$withinTransaction = false`) : aucune
 * écriture bloquée. Un index laissé INVALIDE par une construction interrompue
 * est retiré puis reconstruit (patron de `2026_10_02_000020`). Nom vérifié
 * libre dans le dépôt le 2026-10-03.
 *
 * PUREMENT ADDITIVE. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_activities_a_rattacher';

    private const DEFINITION = "activities (workspace_id, occurred_at, id)
        WHERE subject_id IS NULL
          AND payload -> 'pending_match' IS NOT NULL
          AND payload -> 'arbitrage_dismissed' IS NULL";

    public function up(): void
    {
        if (! Schema::hasTable('activities')) {
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
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
