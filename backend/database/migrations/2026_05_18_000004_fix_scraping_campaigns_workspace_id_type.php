<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 19.8 — fix mismatch type workspace_id.
 *
 * La migration 2026_05_18_000003 a créé `scraping_campaigns.workspace_id UUID`
 * en supposant que `workspaces.id` était UUID. En réalité, `workspaces.id`
 * est `BIGINT` (legacy schema). Tout INSERT plantait avec :
 *   SQLSTATE[22P02]: invalid input syntax for type uuid: "1"
 *
 * Cette migration détecte le type réel de `workspaces.id` et ré-aligne
 * `scraping_campaigns.workspace_id`. Idempotent.
 *
 * Note : pas de data perdue car la table est vide (aucune campagne n'a pu
 * être créée à cause du bug). On drop + add.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
            DO $$
            DECLARE
                v_workspaces_id_type TEXT;
                v_current_type       TEXT;
            BEGIN
                -- Lire le type réel de workspaces.id
                SELECT data_type INTO v_workspaces_id_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'workspaces'
                  AND column_name = 'id';

                -- Lire le type actuel de scraping_campaigns.workspace_id
                SELECT data_type INTO v_current_type
                FROM information_schema.columns
                WHERE table_schema = current_schema()
                  AND table_name = 'scraping_campaigns'
                  AND column_name = 'workspace_id';

                RAISE NOTICE 'workspaces.id type = %, scraping_campaigns.workspace_id type = %',
                    v_workspaces_id_type, v_current_type;

                -- Si déjà aligné, no-op
                IF v_workspaces_id_type = v_current_type THEN
                    RAISE NOTICE 'Types already aligned, skipping fix.';
                    RETURN;
                END IF;

                -- Drop constraint FK puis colonne, recréer dans le bon type
                ALTER TABLE scraping_campaigns
                    DROP CONSTRAINT IF EXISTS scraping_campaigns_workspace_id_fkey;
                DROP INDEX IF EXISTS idx_scraping_campaigns_workspace_status;
                DROP INDEX IF EXISTS idx_scraping_campaigns_running;
                ALTER TABLE scraping_campaigns DROP COLUMN workspace_id;

                IF v_workspaces_id_type IN ('bigint', 'integer') THEN
                    ALTER TABLE scraping_campaigns
                        ADD COLUMN workspace_id BIGINT NOT NULL
                        REFERENCES workspaces(id) ON DELETE CASCADE;
                ELSIF v_workspaces_id_type = 'uuid' THEN
                    ALTER TABLE scraping_campaigns
                        ADD COLUMN workspace_id UUID NOT NULL
                        REFERENCES workspaces(id) ON DELETE CASCADE;
                ELSE
                    -- Fallback (varchar/text) : on prend TEXT pour ne pas perdre la FK
                    ALTER TABLE scraping_campaigns
                        ADD COLUMN workspace_id TEXT NOT NULL
                        REFERENCES workspaces(id) ON DELETE CASCADE;
                END IF;

                CREATE INDEX idx_scraping_campaigns_workspace_status
                    ON scraping_campaigns (workspace_id, status);
                CREATE INDEX idx_scraping_campaigns_running
                    ON scraping_campaigns (workspace_id, started_at DESC)
                    WHERE status = 'running';

                -- Recréer RLS policy (cohérente avec le nouveau type)
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

                RAISE NOTICE 'scraping_campaigns.workspace_id aligned to type %', v_workspaces_id_type;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        // No-op : on ne sait pas l'état précédent, et cette migration est purement corrective.
        // Le rollback de la table elle-même se fait via 2026_05_18_000003 down.
    }
};
