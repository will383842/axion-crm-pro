<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Spec/04 — Phase 2 scaffold complet (35 tables totales).
 * Migration 000007 a déjà créé 11 tables, celle-ci ajoute les 24 manquantes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            -- ===================================================================
            -- Cold email (8 tables)
            -- ===================================================================
            CREATE TABLE email_events (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                email_send_id BIGINT NOT NULL REFERENCES email_sends(id) ON DELETE CASCADE,
                event_type    TEXT NOT NULL CHECK (event_type IN ('delivered','opened','clicked','bounced','complained','unsubscribed','replied')),
                metadata      JSONB NOT NULL DEFAULT '{}'::jsonb,
                occurred_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );
            CREATE INDEX idx_email_events_send ON email_events (email_send_id, occurred_at DESC);

            CREATE TABLE unsubscribes (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                email         CITEXT NOT NULL,
                source        TEXT NOT NULL,
                reason        TEXT,
                unsubscribed_at TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, email)
            );

            CREATE TABLE dnc_lists (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                name          TEXT NOT NULL,
                entries_count INT NOT NULL DEFAULT 0,
                source        TEXT,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE dnc_entries (
                id           BIGSERIAL PRIMARY KEY,
                dnc_list_id  BIGINT NOT NULL REFERENCES dnc_lists(id) ON DELETE CASCADE,
                email        CITEXT,
                phone        TEXT,
                domain       TEXT,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE email_warmup_pools (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                domain       TEXT NOT NULL,
                daily_limit  INT NOT NULL DEFAULT 50,
                current_send_today INT NOT NULL DEFAULT 0,
                reputation_score INT,
                last_reset_at TIMESTAMPTZ
            );

            CREATE TABLE email_inboxes (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                email        CITEXT NOT NULL UNIQUE,
                provider     TEXT NOT NULL,
                connection_blob TEXT,
                last_sync_at TIMESTAMPTZ,
                enabled      BOOLEAN NOT NULL DEFAULT true
            );

            CREATE TABLE email_threads (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL,
                contact_id   BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                campaign_id  BIGINT REFERENCES campaigns(id) ON DELETE SET NULL,
                subject      TEXT,
                last_message_at TIMESTAMPTZ,
                status       TEXT NOT NULL DEFAULT 'open',
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE email_messages (
                id            BIGSERIAL PRIMARY KEY,
                thread_id     BIGINT NOT NULL REFERENCES email_threads(id) ON DELETE CASCADE,
                direction     TEXT NOT NULL CHECK (direction IN ('outbound','inbound')),
                from_address  TEXT NOT NULL,
                to_addresses  TEXT[] NOT NULL,
                subject       TEXT,
                body_text     TEXT,
                body_html     TEXT,
                message_id    TEXT,
                received_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- ===================================================================
            -- LinkedIn outreach (5 tables)
            -- ===================================================================
            CREATE TABLE linkedin_invitations (
                id             BIGSERIAL PRIMARY KEY,
                workspace_id   UUID NOT NULL,
                account_id     BIGINT NOT NULL REFERENCES linkedin_accounts(id) ON DELETE CASCADE,
                contact_id     BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                status         TEXT NOT NULL CHECK (status IN ('queued','sent','accepted','withdrawn','expired')),
                note           TEXT,
                sent_at        TIMESTAMPTZ,
                accepted_at    TIMESTAMPTZ,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE linkedin_sequences (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                name          TEXT NOT NULL,
                steps         JSONB NOT NULL,
                enabled       BOOLEAN NOT NULL DEFAULT true,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE linkedin_sequence_runs (
                id              BIGSERIAL PRIMARY KEY,
                sequence_id     BIGINT NOT NULL REFERENCES linkedin_sequences(id) ON DELETE CASCADE,
                contact_id      BIGINT NOT NULL REFERENCES contacts(id) ON DELETE CASCADE,
                current_step    INT NOT NULL DEFAULT 0,
                status          TEXT NOT NULL DEFAULT 'active',
                started_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                completed_at    TIMESTAMPTZ
            );

            CREATE TABLE linkedin_profiles_cache (
                profile_url  TEXT PRIMARY KEY,
                workspace_id UUID,
                snapshot     JSONB NOT NULL,
                fetched_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                expires_at   TIMESTAMPTZ NOT NULL DEFAULT (now() + INTERVAL '90 days')
            );

            CREATE TABLE linkedin_health_checks (
                id           BIGSERIAL PRIMARY KEY,
                account_id   BIGINT NOT NULL REFERENCES linkedin_accounts(id) ON DELETE CASCADE,
                status       TEXT NOT NULL,
                quota_used   INT,
                quota_max    INT,
                checked_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- ===================================================================
            -- CRM pipeline (6 tables)
            -- ===================================================================
            CREATE TABLE crm_pipelines (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                name          TEXT NOT NULL,
                slug          TEXT NOT NULL,
                is_default    BOOLEAN NOT NULL DEFAULT false,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (workspace_id, slug)
            );

            ALTER TABLE pipeline_stages ADD COLUMN IF NOT EXISTS pipeline_id BIGINT REFERENCES crm_pipelines(id) ON DELETE CASCADE;

            CREATE TABLE crm_lost_reasons (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                slug          TEXT NOT NULL,
                label         TEXT NOT NULL,
                UNIQUE (workspace_id, slug)
            );

            ALTER TABLE deals ADD COLUMN IF NOT EXISTS pipeline_id BIGINT REFERENCES crm_pipelines(id) ON DELETE SET NULL;
            ALTER TABLE deals ADD COLUMN IF NOT EXISTS lost_reason_id BIGINT REFERENCES crm_lost_reasons(id) ON DELETE SET NULL;

            CREATE TABLE deal_history (
                id           BIGSERIAL PRIMARY KEY,
                deal_id      BIGINT NOT NULL REFERENCES deals(id) ON DELETE CASCADE,
                changed_by   UUID REFERENCES users(id),
                field        TEXT NOT NULL,
                old_value    TEXT,
                new_value    TEXT,
                changed_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE crm_notes (
                id           BIGSERIAL PRIMARY KEY,
                workspace_id UUID NOT NULL,
                author_id    UUID REFERENCES users(id) ON DELETE SET NULL,
                deal_id      BIGINT REFERENCES deals(id) ON DELETE CASCADE,
                contact_id   BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
                content      TEXT NOT NULL,
                created_at   TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE crm_tasks (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                assignee_id   UUID REFERENCES users(id) ON DELETE SET NULL,
                deal_id       BIGINT REFERENCES deals(id) ON DELETE CASCADE,
                contact_id    BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
                title         TEXT NOT NULL,
                description   TEXT,
                due_at        TIMESTAMPTZ,
                completed_at  TIMESTAMPTZ,
                priority      TEXT,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            -- ===================================================================
            -- Analytics (5 tables)
            -- ===================================================================
            CREATE TABLE analytics_funnels (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                name          TEXT NOT NULL,
                steps         JSONB NOT NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now()
            );

            CREATE TABLE analytics_funnel_snapshots (
                id           BIGSERIAL PRIMARY KEY,
                funnel_id    BIGINT NOT NULL REFERENCES analytics_funnels(id) ON DELETE CASCADE,
                snapshot_date DATE NOT NULL,
                counts       JSONB NOT NULL,
                UNIQUE (funnel_id, snapshot_date)
            );

            CREATE TABLE analytics_cohorts (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL,
                cohort_period DATE NOT NULL,
                kind          TEXT NOT NULL,
                metric        TEXT NOT NULL,
                value         NUMERIC(18,4),
                metadata      JSONB
            );

            CREATE TABLE analytics_attribution (
                id              BIGSERIAL PRIMARY KEY,
                workspace_id    UUID NOT NULL,
                deal_id         BIGINT REFERENCES deals(id) ON DELETE CASCADE,
                touchpoint      TEXT NOT NULL,
                channel         TEXT,
                weight          NUMERIC(4,3),
                occurred_at     TIMESTAMPTZ NOT NULL
            );

            CREATE TABLE analytics_kpis (
                workspace_id  UUID NOT NULL,
                kpi_key       TEXT NOT NULL,
                value         NUMERIC(18,4),
                computed_at   TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (workspace_id, kpi_key, computed_at)
            );

            -- ===================================================================
            -- RLS sur les nouvelles tables workspace-scoped
            -- ===================================================================
            DO $$
            DECLARE t TEXT;
            BEGIN
                FOR t IN SELECT unnest(ARRAY[
                    'email_events','unsubscribes','dnc_lists','email_warmup_pools','email_inboxes',
                    'email_threads','linkedin_invitations','linkedin_sequences','linkedin_health_checks',
                    'crm_pipelines','crm_lost_reasons','crm_notes','crm_tasks',
                    'analytics_funnels','analytics_cohorts','analytics_attribution','analytics_kpis'
                ])
                LOOP
                    -- Défensif (calqué sur 2026_05_18_000001) : skip si la table
                    -- n'existe pas ou n'a pas de colonne workspace_id (sinon
                    -- « column workspace_id does not exist » casse migrate:fresh).
                    CONTINUE WHEN NOT EXISTS (
                        SELECT 1 FROM information_schema.columns
                        WHERE table_schema = current_schema()
                          AND table_name = t AND column_name = 'workspace_id'
                    );
                    EXECUTE format('ALTER TABLE %I ENABLE ROW LEVEL SECURITY', t);
                    EXECUTE format('DROP POLICY IF EXISTS %I_workspace_isolation ON %I', t, t);
                    EXECUTE format(
                        'CREATE POLICY %I_workspace_isolation ON %I FOR ALL USING (
                            workspace_id IS NULL
                            OR NULLIF(current_setting(''app.current_workspace_id'', true), '''') IS NULL
                            OR workspace_id::TEXT = NULLIF(current_setting(''app.current_workspace_id'', true), '''')
                        ) WITH CHECK (
                            workspace_id IS NULL
                            OR NULLIF(current_setting(''app.current_workspace_id'', true), '''') IS NULL
                            OR workspace_id::TEXT = NULLIF(current_setting(''app.current_workspace_id'', true), '''')
                        )', t, t);
                END LOOP;
            END$$;
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP TABLE IF EXISTS analytics_kpis CASCADE;
            DROP TABLE IF EXISTS analytics_attribution CASCADE;
            DROP TABLE IF EXISTS analytics_cohorts CASCADE;
            DROP TABLE IF EXISTS analytics_funnel_snapshots CASCADE;
            DROP TABLE IF EXISTS analytics_funnels CASCADE;
            DROP TABLE IF EXISTS crm_tasks CASCADE;
            DROP TABLE IF EXISTS crm_notes CASCADE;
            DROP TABLE IF EXISTS deal_history CASCADE;
            ALTER TABLE deals DROP COLUMN IF EXISTS lost_reason_id;
            ALTER TABLE deals DROP COLUMN IF EXISTS pipeline_id;
            DROP TABLE IF EXISTS crm_lost_reasons CASCADE;
            ALTER TABLE pipeline_stages DROP COLUMN IF EXISTS pipeline_id;
            DROP TABLE IF EXISTS crm_pipelines CASCADE;
            DROP TABLE IF EXISTS linkedin_health_checks CASCADE;
            DROP TABLE IF EXISTS linkedin_profiles_cache CASCADE;
            DROP TABLE IF EXISTS linkedin_sequence_runs CASCADE;
            DROP TABLE IF EXISTS linkedin_sequences CASCADE;
            DROP TABLE IF EXISTS linkedin_invitations CASCADE;
            DROP TABLE IF EXISTS email_messages CASCADE;
            DROP TABLE IF EXISTS email_threads CASCADE;
            DROP TABLE IF EXISTS email_inboxes CASCADE;
            DROP TABLE IF EXISTS email_warmup_pools CASCADE;
            DROP TABLE IF EXISTS dnc_entries CASCADE;
            DROP TABLE IF EXISTS dnc_lists CASCADE;
            DROP TABLE IF EXISTS unsubscribes CASCADE;
            DROP TABLE IF EXISTS email_events CASCADE;
        SQL);
    }
};
