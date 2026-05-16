<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Row-Level Security PostgreSQL — isolation par workspace_id en double sécurité
 * (en plus du filtre applicatif via SetCurrentWorkspace middleware).
 *
 * La variable de session `app.current_workspace_id` est positionnée par
 * `SetCurrentWorkspace` (cf. backend/app/Http/Middleware/).
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
            DB::statement("ALTER TABLE $table ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY {$table}_workspace_isolation ON $table
                FOR ALL
                USING (workspace_id IS NULL OR workspace_id::TEXT = current_setting('app.current_workspace_id', true))
                WITH CHECK (workspace_id IS NULL OR workspace_id::TEXT = current_setting('app.current_workspace_id', true))");
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
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON $table");
            DB::statement("ALTER TABLE $table DISABLE ROW LEVEL SECURITY");
        }
    }
};
