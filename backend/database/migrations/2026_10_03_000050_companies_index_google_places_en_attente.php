<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INDEX PARTIEL des fiches « Google Places en attente », au service de l'écran
 * « Santé du système » (`ObservabilityController::requeteGooglePlacesEnAttente`).
 *
 * ── Pourquoi ──────────────────────────────────────────────────────────────
 * Mesuré en production le 2026-10-03, sous `axion_app` (sécurité par espace
 * forcée, 4,35 M de fiches) :
 *
 *     SELECT count(*) FROM companies
 *      WHERE (signals->'google_places_pending') IS NOT NULL
 *        AND (signals->'google_places'->>'enriched_at') IS NULL
 *
 * = 4,6 s, pour un résultat de 0. Aucun index ne portait ces conditions, et
 * les opérateurs JSON `->` / `->>` ne sont pas « leakproof » : sous la
 * sécurité par espace forcée, Postgres ne peut les évaluer qu'après la
 * politique, ligne à ligne, sur le tas — toute la table relue à chaque
 * ouverture de l'écran (et toutes les 30 s tant qu'il reste ouvert).
 *
 * Un index PARTIEL dont le prédicat reprend ces deux conditions MOT POUR MOT
 * ne porte que les fiches en attente (zéro aujourd'hui, quelques milliers au
 * pire en fin de mois de quota) : le planificateur reconnaît le prédicat dans
 * la requête et n'a plus à réévaluer le JSON. La colonne `workspace_id` sert
 * le filtre par espace que la requête porte désormais explicitement.
 *
 * ⚠️ Le prédicat doit rester IDENTIQUE à celui de la requête (et de
 * `RetryGooglePlacesCommand`, qui en profite aussi) : une condition réécrite
 * autrement (`signals ? 'google_places_pending'`, par exemple) ne serait plus
 * reconnue, et l'index ne servirait plus — sans aucune erreur.
 *
 * Le décompte par motif d'archivage (4,1 s mesurées le même jour) n'a PAS
 * besoin d'un nouvel index : `idx_companies_archive_reason (workspace_id,
 * archive_reason) WHERE archive_reason IS NOT NULL` existe depuis
 * `2026_05_18_000006` et porte exactement ce prédicat. Ses 4,1 s viennent des
 * ~484 000 lignes comptées ; il est servi depuis le cache de l'écran.
 *
 * ── Coût en production ────────────────────────────────────────────────────
 * `CONCURRENTLY`, hors transaction (`$withinTransaction = false`) : aucune
 * écriture bloquée, seul un verrou SHARE UPDATE EXCLUSIVE est pris. Deux
 * passages sur la table, quelques minutes au plus. Un index laissé INVALIDE
 * par une construction interrompue est retiré puis reconstruit (patron de
 * `2026_10_02_000020` et `2026_10_03_000040`). Nom vérifié libre dans le dépôt
 * le 2026-10-03.
 *
 * PUREMENT ADDITIVE. `down()` retire l'index.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    private const NOM = 'idx_companies_google_places_en_attente';

    private const DEFINITION = "companies (workspace_id)
        WHERE (signals -> 'google_places_pending') IS NOT NULL
          AND (signals -> 'google_places' ->> 'enriched_at') IS NULL";

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
        DB::statement("SET lock_timeout = '30s'");
        try {
            DB::statement('DROP INDEX CONCURRENTLY IF EXISTS ' . self::NOM);
        } finally {
            DB::statement('RESET lock_timeout');
        }
    }
};
