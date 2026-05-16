<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/04 — Phase 2 SCAFFOLD (campaigns + cold_email + linkedin + crm + analytics).
 * Tables créées vides. Logique métier reportée Phase 2.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- =====================================================================
            -- Phase 2 — Campaigns
            -- =====================================================================
            CREATE TABLE campaigns (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                name          TEXT NOT NULL,
                channel       TEXT NOT NULL CHECK (channel IN ('cold_email','linkedin','mixed')),
                status        TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','active','paused','done','archived')),
                target_filter JSONB NOT NULL DEFAULT '{}'::jsonb,
                metadata      JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at    TIMESTAMPTZ
            );

            -- =====================================================================
            -- Phase 2 — Cold email
            -- =====================================================================
            CREATE TABLE email_templates (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                slug          TEXT NOT NULL,
                subject       TEXT NOT NULL,
                body_html     TEXT NOT NULL,
                body_text     TEXT NOT NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, slug)
            );

            CREATE TABLE email_sequences (
                id            BIGSERIAL PRIMARY KEY,
                campaign_id   BIGINT NOT NULL REFERENCES campaigns(id) ON DELETE CASCADE,
                step          SMALLINT NOT NULL,
                template_id   BIGINT NOT NULL REFERENCES email_templates(id),
                delay_hours   INT NOT NULL DEFAULT 0,
                UNIQUE (campaign_id, step)
            );

            CREATE TABLE email_sends (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                campaign_id   BIGINT REFERENCES campaigns(id) ON DELETE SET NULL,
                contact_id    BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                template_id   BIGINT REFERENCES email_templates(id),
                status        TEXT NOT NULL CHECK (status IN ('queued','sent','delivered','bounced','opened','clicked','replied','unsubscribed')),
                provider      TEXT,
                message_id    TEXT,
                error         TEXT,
                sent_at       TIMESTAMPTZ,
                delivered_at  TIMESTAMPTZ,
                opened_at     TIMESTAMPTZ,
                clicked_at    TIMESTAMPTZ,
                replied_at    TIMESTAMPTZ,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_email_sends_contact ON email_sends (contact_id);
            CREATE INDEX idx_email_sends_status ON email_sends (workspace_id, status);

            -- =====================================================================
            -- Phase 2 — LinkedIn outreach
            -- =====================================================================
            CREATE TABLE linkedin_accounts (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                profile_url   TEXT NOT NULL,
                display_name  TEXT,
                cookie_blob   TEXT,
                proxy_id      BIGINT,
                last_health   TIMESTAMPTZ,
                enabled       BOOLEAN NOT NULL DEFAULT true,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE linkedin_messages (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                account_id    BIGINT REFERENCES linkedin_accounts(id) ON DELETE SET NULL,
                contact_id    BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                kind          TEXT NOT NULL CHECK (kind IN ('connection_request','inmail','message')),
                status        TEXT NOT NULL,
                content       TEXT,
                sent_at       TIMESTAMPTZ,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- =====================================================================
            -- Phase 2 — CRM pipeline
            -- =====================================================================
            CREATE TABLE pipeline_stages (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                slug          TEXT NOT NULL,
                name          TEXT NOT NULL,
                position      INT NOT NULL DEFAULT 0,
                color         TEXT,
                UNIQUE (workspace_id, slug)
            );

            CREATE TABLE deals (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                company_id    BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                primary_contact_id BIGINT REFERENCES contacts(id) ON DELETE SET NULL,
                stage_id      BIGINT REFERENCES pipeline_stages(id),
                value_eur     NUMERIC(12,2),
                probability   SMALLINT CHECK (probability BETWEEN 0 AND 100),
                expected_close TIMESTAMPTZ,
                status        TEXT NOT NULL DEFAULT 'open' CHECK (status IN ('open','won','lost','dormant')),
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE activities (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                deal_id       BIGINT REFERENCES deals(id) ON DELETE CASCADE,
                contact_id    BIGINT REFERENCES contacts(id) ON DELETE SET NULL,
                user_id       UUID REFERENCES users(id) ON DELETE SET NULL,
                type          TEXT NOT NULL,
                content       TEXT,
                due_at        TIMESTAMPTZ,
                done_at       TIMESTAMPTZ,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- =====================================================================
            -- Phase 2 — Analytics rollups
            -- =====================================================================
            CREATE TABLE analytics_daily_rollups (
                workspace_id  UUID NOT NULL,
                day           DATE NOT NULL,
                kind          TEXT NOT NULL,
                value_num     NUMERIC(18,4),
                value_json    JSONB,
                PRIMARY KEY (workspace_id, day, kind)
            );
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS analytics_daily_rollups CASCADE;
            DROP TABLE IF EXISTS activities CASCADE;
            DROP TABLE IF EXISTS deals CASCADE;
            DROP TABLE IF EXISTS pipeline_stages CASCADE;
            DROP TABLE IF EXISTS linkedin_messages CASCADE;
            DROP TABLE IF EXISTS linkedin_accounts CASCADE;
            DROP TABLE IF EXISTS email_sends CASCADE;
            DROP TABLE IF EXISTS email_sequences CASCADE;
            DROP TABLE IF EXISTS email_templates CASCADE;
            DROP TABLE IF EXISTS campaigns CASCADE;
        SQL);
    }
};
