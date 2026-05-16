<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/03 § §7 + §8 — coverage matrix + dedup + RGPD + AI Act + notifications + saved views.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- =====================================================================
            -- §7 coverage — matrix rollup
            -- =====================================================================
            CREATE TABLE coverage_zones (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                department    TEXT NOT NULL REFERENCES departments(code),
                naf           TEXT,
                size_category TEXT,
                attempted_at  TIMESTAMPTZ,
                completed_at  TIMESTAMPTZ,
                cooldown_until TIMESTAMPTZ,
                metadata      JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, department, naf, size_category)
            );
            CREATE INDEX idx_coverage_zones_workspace ON coverage_zones (workspace_id);
            CREATE INDEX idx_coverage_zones_cooldown ON coverage_zones (cooldown_until) WHERE cooldown_until IS NOT NULL;

            -- Materialized view : Sprint 9 — rollup hourly via pg_cron + COMPUTE.
            CREATE MATERIALIZED VIEW coverage_matrix_cells AS
                SELECT
                    c.workspace_id,
                    c.postcode AS postcode,
                    LEFT(c.postcode, 2) AS dept_code,
                    c.naf,
                    c.size_category,
                    COUNT(*) AS company_count,
                    SUM(CASE WHEN c.quality_score >= 90 THEN 1 ELSE 0 END) AS complete_count,
                    SUM(CASE WHEN c.quality_score BETWEEN 50 AND 89 THEN 1 ELSE 0 END) AS partial_count,
                    MAX(c.enriched_at) AS last_enriched_at
                FROM companies c
                WHERE c.deleted_at IS NULL
                GROUP BY c.workspace_id, c.postcode, c.naf, c.size_category
                WITH NO DATA;
            CREATE UNIQUE INDEX idx_coverage_matrix_cells_pk ON coverage_matrix_cells (workspace_id, postcode, naf, size_category);

            -- =====================================================================
            -- duplicate flags (fuzzy matching humain à valider)
            -- =====================================================================
            CREATE TABLE duplicate_flags (
                id              BIGSERIAL PRIMARY KEY,
                workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                entity_type     TEXT NOT NULL CHECK (entity_type IN ('company','contact')),
                entity_a_id     BIGINT NOT NULL,
                entity_b_id     BIGINT NOT NULL,
                similarity      NUMERIC(4,3) NOT NULL,
                reviewed_at     TIMESTAMPTZ,
                reviewed_by     UUID REFERENCES users(id),
                resolution      TEXT CHECK (resolution IN ('merge','keep_both','dismiss')),
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, entity_type, entity_a_id, entity_b_id)
            );
            CREATE INDEX idx_dup_flags_pending ON duplicate_flags (workspace_id, similarity DESC) WHERE reviewed_at IS NULL;

            -- =====================================================================
            -- §8 RGPD + AI Act
            -- =====================================================================
            CREATE TABLE rgpd_requests (
                id                  BIGSERIAL PRIMARY KEY,
                workspace_id        UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                type                TEXT NOT NULL CHECK (type IN ('access','portability','erasure','rectification','opposition')),
                status              TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','processing','done','rejected','expired')),
                subject_email       CITEXT NOT NULL,
                requested_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
                processed_at        TIMESTAMPTZ,
                processed_by        UUID REFERENCES users(id),
                export_token        TEXT,
                export_expires_at   TIMESTAMPTZ,
                metadata            JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at          TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at          TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_rgpd_workspace ON rgpd_requests (workspace_id, status);
            CREATE INDEX idx_rgpd_subject ON rgpd_requests (subject_email);

            CREATE TABLE ai_act_register (
                id              BIGSERIAL PRIMARY KEY,
                workspace_id    UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                system_name     TEXT NOT NULL,
                purpose         TEXT NOT NULL,
                risk_class      TEXT NOT NULL CHECK (risk_class IN ('prohibited','high','limited','minimal')),
                provider        TEXT,
                model           TEXT,
                dpia_url        TEXT,
                impact_assessment JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- =====================================================================
            -- notifications (header 🔔)
            -- =====================================================================
            CREATE TABLE notifications (
                id            UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                type          TEXT NOT NULL,
                title         TEXT NOT NULL,
                body          TEXT,
                action_url    TEXT,
                read_at       TIMESTAMPTZ,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_notifications_user_unread ON notifications (user_id, created_at DESC) WHERE read_at IS NULL;

            -- saved_views (filtres tables sauvegardés par utilisateur)
            CREATE TABLE saved_views (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                user_id       UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                entity        TEXT NOT NULL,
                name          TEXT NOT NULL,
                filters       JSONB NOT NULL DEFAULT '{}'::jsonb,
                is_default    BOOLEAN NOT NULL DEFAULT false,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (user_id, entity, name)
            );

            -- email_validations (cache validation SMTP 30j TTL)
            CREATE TABLE email_validations (
                id              BIGSERIAL PRIMARY KEY,
                email           CITEXT NOT NULL UNIQUE,
                status          TEXT NOT NULL,
                score           INT,
                mx_host         TEXT,
                is_catchall     BOOLEAN NOT NULL DEFAULT false,
                is_disposable   BOOLEAN NOT NULL DEFAULT false,
                is_role         BOOLEAN NOT NULL DEFAULT false,
                checked_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                expires_at      TIMESTAMPTZ NOT NULL DEFAULT (now() + INTERVAL '30 days')
            );
            CREATE INDEX idx_email_validations_expires ON email_validations (expires_at);

            -- web_vitals_samples (frontend RUM)
            CREATE TABLE web_vital_samples (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID REFERENCES workspaces(id) ON DELETE CASCADE,
                user_id       UUID REFERENCES users(id) ON DELETE SET NULL,
                metric        TEXT NOT NULL,
                value         DOUBLE PRECISION NOT NULL,
                path          TEXT,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_web_vital_samples_metric ON web_vital_samples (metric, created_at DESC);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS web_vital_samples CASCADE;
            DROP TABLE IF EXISTS email_validations CASCADE;
            DROP TABLE IF EXISTS saved_views CASCADE;
            DROP TABLE IF EXISTS notifications CASCADE;
            DROP TABLE IF EXISTS ai_act_register CASCADE;
            DROP TABLE IF EXISTS rgpd_requests CASCADE;
            DROP TABLE IF EXISTS duplicate_flags CASCADE;
            DROP MATERIALIZED VIEW IF EXISTS coverage_matrix_cells CASCADE;
            DROP TABLE IF EXISTS coverage_zones CASCADE;
        SQL);
    }
};
