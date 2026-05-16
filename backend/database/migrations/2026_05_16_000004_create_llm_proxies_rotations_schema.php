<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/03 § §5 + §6 + §7 — LLM router + proxies + rotations.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- =====================================================================
            -- llm_use_cases
            -- =====================================================================
            CREATE TABLE llm_use_cases (
                id                 BIGSERIAL PRIMARY KEY,
                workspace_id       UUID REFERENCES workspaces(id) ON DELETE CASCADE,
                slug               TEXT NOT NULL,
                description        TEXT,
                primary_provider   TEXT NOT NULL,
                model              TEXT NOT NULL,
                fallback_chain     JSONB NOT NULL DEFAULT '[]'::jsonb,
                prompt_version     INT NOT NULL DEFAULT 1,
                options            JSONB NOT NULL DEFAULT '{}'::jsonb,
                cost_cap_eur       NUMERIC(10,4),
                enabled            BOOLEAN NOT NULL DEFAULT true,
                created_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at         TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, slug)
            );

            -- prompt_templates + versions
            CREATE TABLE prompt_templates (
                id            BIGSERIAL PRIMARY KEY,
                use_case_id   BIGINT NOT NULL REFERENCES llm_use_cases(id) ON DELETE CASCADE,
                slug          TEXT NOT NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (use_case_id, slug)
            );

            CREATE TABLE prompt_template_versions (
                id                BIGSERIAL PRIMARY KEY,
                prompt_template_id BIGINT NOT NULL REFERENCES prompt_templates(id) ON DELETE CASCADE,
                version           INT NOT NULL,
                content           TEXT NOT NULL,
                changelog         TEXT,
                created_by        UUID REFERENCES users(id),
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (prompt_template_id, version)
            );

            -- llm_usage : ligne par appel LLM (cost tracking)
            CREATE TABLE llm_usage (
                id              BIGSERIAL PRIMARY KEY,
                workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                use_case_slug   TEXT NOT NULL,
                provider        TEXT NOT NULL,
                model           TEXT NOT NULL,
                tokens_input    INT NOT NULL DEFAULT 0,
                tokens_output   INT NOT NULL DEFAULT 0,
                cost_eur        NUMERIC(10,6) NOT NULL DEFAULT 0,
                latency_ms      INT,
                cache_hit       BOOLEAN NOT NULL DEFAULT false,
                request_hash    TEXT,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_llm_usage_workspace_created ON llm_usage (workspace_id, created_at DESC);
            CREATE INDEX idx_llm_usage_request_hash ON llm_usage (request_hash) WHERE request_hash IS NOT NULL;

            -- =====================================================================
            -- proxy_providers_config — RUNTIME-CONFIG via admin UI
            -- =====================================================================
            CREATE TABLE proxy_providers_config (
                id                    BIGSERIAL PRIMARY KEY,
                workspace_id          UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                slug                  TEXT NOT NULL,
                type                  TEXT NOT NULL CHECK (type IN ('residential','datacenter','mobile')),
                zone                  TEXT NOT NULL DEFAULT 'eu',
                enabled               BOOLEAN NOT NULL DEFAULT true,
                weight                INT NOT NULL DEFAULT 1,
                endpoints_count       INT NOT NULL DEFAULT 0,
                last_health_check_at  TIMESTAMPTZ,
                last_health_status    TEXT,
                metadata              JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at            TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, slug)
            );

            -- proxy_usage_log (1 ligne par requête sortante via proxy)
            CREATE TABLE proxy_usage_log (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                provider_slug TEXT NOT NULL,
                endpoint      TEXT NOT NULL,
                target_host   TEXT NOT NULL,
                status_code   SMALLINT,
                latency_ms    INT,
                bytes         INT,
                used_at       TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_proxy_usage_provider_time ON proxy_usage_log (provider_slug, used_at DESC);

            -- =====================================================================
            -- rotations : 5 dimensions (proxies, UA, cibles, moteurs, LLM)
            -- =====================================================================
            CREATE TABLE rotations (
                id                BIGSERIAL PRIMARY KEY,
                workspace_id      UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                dimension         TEXT NOT NULL CHECK (dimension IN ('proxy','user_agent','target','search_engine','llm')),
                slug              TEXT NOT NULL,
                weight            INT NOT NULL DEFAULT 1,
                cooldown_seconds  INT NOT NULL DEFAULT 0,
                enabled           BOOLEAN NOT NULL DEFAULT true,
                metadata          JSONB NOT NULL DEFAULT '{}'::jsonb,
                last_used_at      TIMESTAMPTZ,
                created_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, dimension, slug)
            );

            -- user_agents library (50+ UA seedés)
            CREATE TABLE user_agents (
                id           BIGSERIAL PRIMARY KEY,
                ua_string    TEXT NOT NULL UNIQUE,
                family       TEXT,
                weight       INT NOT NULL DEFAULT 1,
                enabled      BOOLEAN NOT NULL DEFAULT true,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- search_engines library
            CREATE TABLE search_engines (
                slug         TEXT PRIMARY KEY,
                name         TEXT NOT NULL,
                base_url     TEXT NOT NULL,
                enabled      BOOLEAN NOT NULL DEFAULT true,
                weight       INT NOT NULL DEFAULT 1
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS search_engines CASCADE;
            DROP TABLE IF EXISTS user_agents CASCADE;
            DROP TABLE IF EXISTS rotations CASCADE;
            DROP TABLE IF EXISTS proxy_usage_log CASCADE;
            DROP TABLE IF EXISTS proxy_providers_config CASCADE;
            DROP TABLE IF EXISTS llm_usage CASCADE;
            DROP TABLE IF EXISTS prompt_template_versions CASCADE;
            DROP TABLE IF EXISTS prompt_templates CASCADE;
            DROP TABLE IF EXISTS llm_use_cases CASCADE;
        SQL);
    }
};
