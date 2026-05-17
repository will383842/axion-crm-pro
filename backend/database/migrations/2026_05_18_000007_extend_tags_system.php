<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint Prospection Pipeline 360° — Extension de la taxonomie tags.
 *
 * Les tables `tags` et `company_tag` existent déjà (migration 000003).
 * Cette migration enrichit le système avec :
 *  - tags.category (geo|sector|size|intent|custom) → groupement UI
 *  - tags.kind (auto|manual|llm) → distingue tags algorithmiques vs humains
 *  - company_tag.workspace_id (UUID) → RLS isolation niveau ligne
 *  - company_tag.assigned_at + assigned_by → audit du qui/quand
 *
 * Backfill : company_tag.workspace_id = tags.workspace_id (cohérent par construction).
 *
 * Idempotent : ADD COLUMN IF NOT EXISTS partout.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Étendre la table `tags`
        DB::unprepared(<<<'SQL'
            ALTER TABLE tags
                ADD COLUMN IF NOT EXISTS category VARCHAR(32) NOT NULL DEFAULT 'custom',
                ADD COLUMN IF NOT EXISTS kind     VARCHAR(16) NOT NULL DEFAULT 'manual';

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'tags_category_check'
                ) THEN
                    ALTER TABLE tags
                        ADD CONSTRAINT tags_category_check
                        CHECK (category IN ('geo','sector','size','intent','custom'));
                END IF;

                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'tags_kind_check'
                ) THEN
                    ALTER TABLE tags
                        ADD CONSTRAINT tags_kind_check
                        CHECK (kind IN ('auto','manual','llm'));
                END IF;
            END
            $$;

            CREATE INDEX IF NOT EXISTS idx_tags_workspace_category
                ON tags (workspace_id, category);
            CREATE INDEX IF NOT EXISTS idx_tags_workspace_kind
                ON tags (workspace_id, kind);
        SQL);

        // 2. Étendre la table pivot `company_tag`
        DB::unprepared(<<<'SQL'
            ALTER TABLE company_tag
                ADD COLUMN IF NOT EXISTS workspace_id UUID,
                ADD COLUMN IF NOT EXISTS assigned_at  TIMESTAMPTZ NOT NULL DEFAULT now(),
                ADD COLUMN IF NOT EXISTS assigned_by  VARCHAR(32) NOT NULL DEFAULT 'user';

            DO $$
            BEGIN
                IF NOT EXISTS (
                    SELECT 1 FROM pg_constraint WHERE conname = 'company_tag_assigned_by_check'
                ) THEN
                    ALTER TABLE company_tag
                        ADD CONSTRAINT company_tag_assigned_by_check
                        CHECK (assigned_by IN ('auto-rule','llm','user'));
                END IF;
            END
            $$;
        SQL);

        // 3. Backfill workspace_id sur company_tag depuis tags
        DB::unprepared(<<<'SQL'
            UPDATE company_tag ct
            SET workspace_id = t.workspace_id
            FROM tags t
            WHERE ct.tag_id = t.id
              AND ct.workspace_id IS NULL;
        SQL);

        // 4. RLS sur company_tag (workspace isolation)
        DB::unprepared(<<<'SQL'
            ALTER TABLE company_tag ENABLE ROW LEVEL SECURITY;
            DROP POLICY IF EXISTS company_tag_workspace_isolation ON company_tag;
            CREATE POLICY company_tag_workspace_isolation ON company_tag
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

            CREATE INDEX IF NOT EXISTS idx_company_tag_workspace
                ON company_tag (workspace_id);
        SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP INDEX IF EXISTS idx_company_tag_workspace;
            DROP POLICY IF EXISTS company_tag_workspace_isolation ON company_tag;
            ALTER TABLE company_tag DISABLE ROW LEVEL SECURITY;

            ALTER TABLE company_tag
                DROP CONSTRAINT IF EXISTS company_tag_assigned_by_check;
            ALTER TABLE company_tag
                DROP COLUMN IF EXISTS assigned_by,
                DROP COLUMN IF EXISTS assigned_at,
                DROP COLUMN IF EXISTS workspace_id;

            DROP INDEX IF EXISTS idx_tags_workspace_kind;
            DROP INDEX IF EXISTS idx_tags_workspace_category;
            ALTER TABLE tags
                DROP CONSTRAINT IF EXISTS tags_kind_check,
                DROP CONSTRAINT IF EXISTS tags_category_check;
            ALTER TABLE tags
                DROP COLUMN IF EXISTS kind,
                DROP COLUMN IF EXISTS category;
        SQL);
    }
};
