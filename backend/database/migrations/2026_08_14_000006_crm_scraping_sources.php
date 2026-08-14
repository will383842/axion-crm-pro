<?php

use Database\Seeders\ScrapingSourcesSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LOT L3 (autopilot CRM) — REGISTRE DES SOURCES DE COLLECTE.
 *
 * Aujourd'hui la connaissance des sources est éclatée en constantes de code :
 * `DeduplicationService::SOURCE_TTL_DAYS`, listes en dur dans `buildDedupKey`,
 * flags `MOCK_*` d'environnement. Conséquence concrète (audit scraping §C.2) :
 * couper une source demande un redéploiement, le TTL est invisible depuis la
 * console, et l'argumentaire « intérêt légitime » n'est écrit nulle part.
 *
 * Le registre centralise tout cela dans UNE table :
 *   - `enabled`  : kill-switch par source, sans redéploiement ;
 *   - `ttl_days` : remplace SOURCE_TTL_DAYS en dur (le code le lit ici) ;
 *   - `legal_note` : caractère public de la source, source par source —
 *     l'argumentaire intérêt légitime prêt pour un contrôle CNIL ;
 *   - `dedup_key_pattern` : siren / url / query (aujourd'hui un match en dur) ;
 *   - `quota_per_day` : budget de collecte (Places : quota déjà géré ad hoc).
 *
 * Table GLOBALE (pas de workspace_id) : c'est de l'infrastructure de collecte,
 * comme `opt_out` — aucune PII, aucune donnée métier. Elle ne porte donc pas
 * de RLS : la politique dynamique de L0 ne scope que les tables à
 * `workspace_id`, ce que le test d'inventaire vérifie déjà.
 *
 * MIGRATION PUREMENT ADDITIVE : une table neuve, rien d'existant touché.
 * Le funnel qui la consomme est derrière `CRM_SCRAPE_FUNNEL_ENABLED=false`.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS scraping_sources (
                id                BIGSERIAL PRIMARY KEY,
                slug              TEXT NOT NULL UNIQUE,
                name              TEXT NOT NULL,
                kind              TEXT NOT NULL CHECK (kind IN ('api', 'http_scrape', 'browser_scrape', 'import', 'llm_extract')),
                enabled           BOOLEAN NOT NULL DEFAULT true,
                ttl_days          INT NOT NULL DEFAULT 30 CHECK (ttl_days > 0),
                legal_note        TEXT,
                dedup_key_pattern TEXT NOT NULL DEFAULT 'siren' CHECK (dedup_key_pattern IN ('siren', 'url', 'query')),
                quota_per_day     INT CHECK (quota_per_day IS NULL OR quota_per_day > 0),
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT now()
            );
        SQL);

        // Le registre est SEMÉ par la migration elle-même : les seeders ne
        // tournent pas au déploiement (l'entrypoint ne fait que `migrate
        // deploy`), et un registre vide ferait retomber les TTL sur les
        // constantes en silence. Idempotent (upsert par slug) et sans risque :
        // le funnel qui le consomme est derrière un drapeau à OFF.
        (new ScrapingSourcesSeeder)->run();
    }

    public function down(): void
    {
        DB::unprepared('DROP TABLE IF EXISTS scraping_sources;');
    }
};
