<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security PostgreSQL — isolation par workspace_id en double sécurité
 * (en plus du filtre applicatif via SetCurrentWorkspace middleware).
 *
 * La variable de session `app.current_workspace_id` est positionnée par
 * `SetCurrentWorkspace` (cf. backend/app/Http/Middleware/).
 *
 * Sprint 19.1 — patch : version DÉFENSIVE qui skip dynamiquement les tables
 * absentes ou sans workspace_id (cas Phase 2 dont les tables arrivent dans
 * une migration postérieure). L'autorité finale est la migration
 * 2026_05_18_000001_apply_rls_dynamic.php qui ré-applique tout proprement.
 */
return new class extends Migration
{
    public function up(): void
    {
        $workspaceScopedTables = [
            'companies', 'contacts', 'scraper_runs', 'tags', 'company_tag',
            'llm_use_cases', 'llm_usage', 'prompt_templates', 'prompt_template_versions',
            'proxy_providers_config', 'rotations', 'strategic_keywords',
            'coverage_zones', 'duplicate_flags', 'rgpd_requests', 'ai_act_register',
            'notifications', 'saved_views', 'invitations', 'magic_links',
            'campaigns', 'email_templates', 'email_sequences', 'email_sends',
            'linkedin_accounts', 'linkedin_messages', 'pipeline_stages', 'deals', 'activities',
            'analytics_daily_rollups',
        ];

        foreach ($workspaceScopedTables as $table) {
            // Sprint 19.1 : skip si table absente ou pas de workspace_id
            $tableExists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$table]
            );
            if (! $tableExists) {
                continue;
            }
            $columnExists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, 'workspace_id']
            );
            if (! $columnExists) {
                continue;
            }

            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
            // NULLIF(..., '') gère le cas où current_setting() retourne '' (missing_ok=true)
            // au lieu de NULL — évite que toutes les rows soient invisibles quand la session
            // var n'est pas positionnée (jobs system / migrations / seeders).
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON \"{$table}\"");
            DB::statement("CREATE POLICY {$table}_workspace_isolation ON \"{$table}\"
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
                )");
        }

        // L'utilisateur SQL applicatif n'est pas superuser → RLS s'applique.
        // Override pour les jobs system (migrations, seeders, retention-purge) via BYPASSRLS.
        // (Configuration côté infra spec/02 § Postgres roles.)
    }

    public function down(): void
    {
        $tables = [
            'companies', 'contacts', 'scraper_runs', 'tags', 'company_tag',
            'llm_use_cases', 'llm_usage', 'prompt_templates', 'prompt_template_versions',
            'proxy_providers_config', 'rotations', 'strategic_keywords',
            'coverage_zones', 'duplicate_flags', 'rgpd_requests', 'ai_act_register',
            'notifications', 'saved_views', 'invitations', 'magic_links',
            'campaigns', 'email_templates', 'email_sequences', 'email_sends',
            'linkedin_accounts', 'linkedin_messages', 'pipeline_stages', 'deals', 'activities',
            'analytics_daily_rollups',
        ];

        foreach ($tables as $table) {
            $tableExists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$table]
            );
            if (! $tableExists) {
                continue;
            }
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON \"{$table}\"");
            DB::statement("ALTER TABLE \"{$table}\" DISABLE ROW LEVEL SECURITY");
        }
    }
};
