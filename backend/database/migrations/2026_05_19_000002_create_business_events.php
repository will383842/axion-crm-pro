<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint H4 (2026-05-17) — Table d'audit business events.
 *
 * Distincte de audit_logs (hash chain HTTP middleware AuditHashChainLogger).
 * Sert aux events métier waterfall :
 *   - audience.refreshed
 *   - audience.created
 *   - company.tags_synced
 *   - company.archived
 *   - email.verified
 *
 * Pas de hash chain ici (intégrité non critique pour business events).
 * RLS workspace_isolation activée.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('business_events')) {
            return;
        }

        DB::statement(<<<'SQL'
            CREATE TABLE business_events (
                id             BIGSERIAL PRIMARY KEY,
                workspace_id   UUID NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
                actor_user_id  UUID REFERENCES users(id) ON DELETE SET NULL,
                action         VARCHAR(64) NOT NULL,
                resource_type  VARCHAR(64),
                resource_id    VARCHAR(64),
                context        JSONB,
                created_at     TIMESTAMPTZ NOT NULL DEFAULT now()
            );
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_business_events_workspace_action_created
                ON business_events (workspace_id, action, created_at DESC);
        SQL);

        DB::statement(<<<'SQL'
            CREATE INDEX IF NOT EXISTS idx_business_events_resource
                ON business_events (workspace_id, resource_type, resource_id)
                WHERE resource_type IS NOT NULL;
        SQL);

        // RLS
        DB::statement('ALTER TABLE business_events ENABLE ROW LEVEL SECURITY');
        DB::statement(<<<'SQL'
            CREATE POLICY business_events_workspace_isolation
                ON business_events
                FOR ALL
                USING (
                    workspace_id::TEXT = COALESCE(
                        NULLIF(current_setting('app.current_workspace_id', true), ''),
                        workspace_id::TEXT
                    )
                );
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('business_events');
    }
};
