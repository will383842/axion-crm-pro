<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint Prospection Pipeline 360° — Audiences pour campagnes email (préparation).
 *
 * Crée 2 tables :
 *  - email_audiences : segmentation réutilisable (criteria JSONB DSL)
 *  - audience_members : index pré-calculé (refresh cron + waterfall step12)
 *
 * Le moteur de matching = AudienceBuilderService.
 * L'envoi d'email lui-même sera codé dans un sprint ultérieur — cette migration
 * pose juste l'architecture data + audience_members ready pour plug-and-play.
 *
 * RLS workspace_isolation sur les deux tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS email_audiences (
                id            BIGSERIAL PRIMARY KEY,
                workspace_id  UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                name          VARCHAR(160) NOT NULL,
                description   TEXT,
                criteria      JSONB NOT NULL DEFAULT '{}'::jsonb,
                is_active     BOOLEAN NOT NULL DEFAULT true,
                auto_refresh  BOOLEAN NOT NULL DEFAULT true,
                member_count  INTEGER NOT NULL DEFAULT 0,
                refreshed_at  TIMESTAMPTZ,
                created_by    UUID REFERENCES users(id) ON DELETE SET NULL,
                created_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT now(),
                deleted_at    TIMESTAMPTZ
            );

            CREATE INDEX IF NOT EXISTS idx_email_audiences_workspace
                ON email_audiences (workspace_id);
            CREATE INDEX IF NOT EXISTS idx_email_audiences_workspace_active
                ON email_audiences (workspace_id, is_active) WHERE deleted_at IS NULL;

            ALTER TABLE email_audiences ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS email_audiences_workspace_isolation ON email_audiences;
            CREATE POLICY email_audiences_workspace_isolation ON email_audiences
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

        DB::unprepared(<<<'SQL'
            CREATE TABLE IF NOT EXISTS audience_members (
                id            BIGSERIAL PRIMARY KEY,
                audience_id   BIGINT NOT NULL REFERENCES email_audiences(id) ON DELETE CASCADE,
                company_id    BIGINT NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
                contact_id    BIGINT REFERENCES contacts(id) ON DELETE CASCADE,
                workspace_id  UUID NOT NULL,
                added_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                UNIQUE (audience_id, company_id, contact_id)
            );

            CREATE INDEX IF NOT EXISTS idx_audience_members_workspace
                ON audience_members (workspace_id, audience_id);
            CREATE INDEX IF NOT EXISTS idx_audience_members_company
                ON audience_members (company_id);
            CREATE INDEX IF NOT EXISTS idx_audience_members_contact
                ON audience_members (contact_id) WHERE contact_id IS NOT NULL;

            ALTER TABLE audience_members ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS audience_members_workspace_isolation ON audience_members;
            CREATE POLICY audience_members_workspace_isolation ON audience_members
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
            DROP TABLE IF EXISTS audience_members CASCADE;
            DROP TABLE IF EXISTS email_audiences CASCADE;
        SQL);
    }
};
