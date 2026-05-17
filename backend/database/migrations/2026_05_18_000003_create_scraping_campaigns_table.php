<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 19.7 — scraping_campaigns.
 *
 * Une « campagne » orchestre N scraper_runs avec :
 *  - budget global (entreprises max, durée max, débit max)
 *  - budgets par source (rpm/daily quotas)
 *  - planning (scheduled_at, expires_at)
 *  - progression auto-recalcule (companies_created / requests_made / duration_seconds_used)
 *  - auto-pause si quota atteint (paused_reason ∈ quota_companies | quota_duration | manual | rate_limit)
 *
 * Lie chaque scraper_run au campaign_id (nullable, mode legacy /coverage/launch préservé).
 *
 * Raw SQL Postgres pour rester cohérent avec les migrations précédentes
 * (UUID workspace_id, generated columns, RLS policy posée par 2026_05_18_000001 si table existe à ce moment-là,
 * sinon par re-run de la migration RLS dynamic).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- =====================================================================
            -- scraping_campaigns
            -- =====================================================================
            CREATE TABLE IF NOT EXISTS scraping_campaigns (
                id                       BIGSERIAL PRIMARY KEY,
                workspace_id             UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                created_by               UUID NOT NULL REFERENCES users(id) ON DELETE RESTRICT,
                name                     TEXT NOT NULL,
                description              VARCHAR(500),
                status                   TEXT NOT NULL DEFAULT 'draft'
                    CHECK (status IN ('draft','scheduled','running','paused','completed','failed','cancelled')),
                -- Sources sélectionnées (whitelist appliquée côté FormRequest)
                sources                  JSONB NOT NULL DEFAULT '[]'::jsonb,
                -- Zones cibles : [{ type:'department'|'region'|'city', code:'75' }, ...]
                zones                    JSONB NOT NULL DEFAULT '[]'::jsonb,
                -- Budgets / limites
                max_companies            INTEGER NOT NULL DEFAULT 1000
                    CHECK (max_companies BETWEEN 1 AND 50000),
                max_duration_minutes     INTEGER NOT NULL DEFAULT 180
                    CHECK (max_duration_minutes BETWEEN 5 AND 1440),
                max_requests_per_minute  INTEGER NOT NULL DEFAULT 30
                    CHECK (max_requests_per_minute BETWEEN 1 AND 100),
                per_source_limits        JSONB,
                -- Planning
                scheduled_at             TIMESTAMPTZ,
                expires_at               TIMESTAMPTZ,
                -- Progression (mis à jour par les workers + MonitorCampaignProgressJob)
                companies_created        INTEGER NOT NULL DEFAULT 0,
                requests_made            INTEGER NOT NULL DEFAULT 0,
                runs_completed           INTEGER NOT NULL DEFAULT 0,
                runs_total               INTEGER NOT NULL DEFAULT 0,
                duration_seconds_used    INTEGER NOT NULL DEFAULT 0,
                -- Timestamps lifecycle
                started_at               TIMESTAMPTZ,
                paused_at                TIMESTAMPTZ,
                finished_at              TIMESTAMPTZ,
                paused_reason            VARCHAR(255),
                -- Standard timestamps + soft delete
                created_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at               TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at               TIMESTAMPTZ
            );

            CREATE INDEX IF NOT EXISTS idx_scraping_campaigns_workspace_status
                ON scraping_campaigns (workspace_id, status);
            CREATE INDEX IF NOT EXISTS idx_scraping_campaigns_scheduled_at
                ON scraping_campaigns (status, scheduled_at)
                WHERE status = 'scheduled';
            CREATE INDEX IF NOT EXISTS idx_scraping_campaigns_running
                ON scraping_campaigns (workspace_id, started_at DESC)
                WHERE status = 'running';

            -- =====================================================================
            -- Lier scraper_runs à une campagne (nullable, mode legacy préservé)
            -- =====================================================================
            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'scraper_runs'
                      AND column_name = 'campaign_id'
                ) THEN
                    ALTER TABLE scraper_runs
                        ADD COLUMN campaign_id BIGINT
                            REFERENCES scraping_campaigns(id) ON DELETE SET NULL;
                    CREATE INDEX idx_runs_campaign ON scraper_runs (campaign_id);
                END IF;
            END
            $$;

            -- =====================================================================
            -- RLS workspace isolation (cohérent avec 2026_05_18_000001_apply_rls_dynamic)
            -- =====================================================================
            ALTER TABLE scraping_campaigns ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS scraping_campaigns_workspace_isolation ON scraping_campaigns;
            CREATE POLICY scraping_campaigns_workspace_isolation ON scraping_campaigns
                FOR ALL
                USING (
                    workspace_id IS NULL
                    OR NULLIF(current_setting('app.current_workspace_id', true), '') IS NULL
                    OR workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
                )
                WITH CHECK (
                    workspace_id IS NULL
                    OR NULLIF(current_setting('app.current_workspace_id', true), '') IS NULL
                    OR workspace_id::TEXT = NULLIF(current_setting('app.current_workspace_id', true), '')
                );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            BEGIN
                IF EXISTS (
                    SELECT 1 FROM information_schema.columns
                    WHERE table_schema = current_schema()
                      AND table_name = 'scraper_runs'
                      AND column_name = 'campaign_id'
                ) THEN
                    DROP INDEX IF EXISTS idx_runs_campaign;
                    ALTER TABLE scraper_runs DROP COLUMN campaign_id;
                END IF;
            END
            $$;
            DROP TABLE IF EXISTS scraping_campaigns CASCADE;
        SQL);
    }
};
