<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint 19.1 — RLS dynamiques.
 *
 * La migration 2026_05_16_000008_enable_rls_policies posait RLS sur 28 tables
 * en assumant que chacune existait et avait la colonne workspace_id. En réalité :
 *   - certaines tables Phase 2 sont créées dans 2026_05_17_000010 (postérieure)
 *   - quelques tables ne portent pas workspace_id directement (pivots, lookups)
 * → en prod l'ordre des migrations + l'absence d'idempotence ont conduit à un
 *   état partiel.
 *
 * Cette migration :
 *   1) Parcourt une LISTE EXHAUSTIVE de tables workspace-scoped attendues
 *   2) Pour chaque table, check via information_schema si elle existe ET porte workspace_id
 *   3) Si oui : ENABLE RLS + CREATE POLICY (idempotent via DROP IF EXISTS)
 *   4) Sinon : skip et insère un commentaire dans un log table
 *
 * Cette migration est sûre à re-run et reflète l'état réel de la DB.
 */
return new class extends Migration
{
    private array $workspaceScopedTables = [
        // Sprint 16/17 phase 1
        'companies', 'contacts', 'scraper_runs', 'tags', 'company_tag',
        'llm_use_cases', 'llm_usage', 'prompt_templates', 'prompt_template_versions',
        'proxy_providers_config', 'rotations', 'strategic_keywords',
        'coverage_zones', 'duplicate_flags', 'rgpd_requests', 'ai_act_register',
        'notifications', 'saved_views', 'invitations', 'magic_links',
        // Phase 2
        'campaigns', 'email_templates', 'email_sequences', 'email_sends',
        'linkedin_accounts', 'linkedin_messages', 'pipeline_stages', 'deals', 'activities',
        'analytics_daily_rollups',
        // Phase 2 extension (Sprint 16 — 2026_05_17_000010)
        'email_events', 'unsubscribes', 'dnc_lists', 'dnc_entries',
        'campaign_steps', 'campaign_recipients', 'contact_lists', 'contact_list_members',
        'linkedin_campaigns', 'linkedin_search_results',
        'pipelines', 'deal_stage_history', 'deal_contacts', 'tasks',
        'analytics_snapshots', 'analytics_funnels',
    ];

    public function up(): void
    {
        $applied = [];
        $skippedNoTable = [];
        $skippedNoColumn = [];

        foreach ($this->workspaceScopedTables as $table) {
            // Vérifie existence de la table
            $tableExists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$table]
            );
            if (! $tableExists) {
                $skippedNoTable[] = $table;
                continue;
            }

            // Vérifie présence de workspace_id
            $columnExists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?',
                [$table, 'workspace_id']
            );
            if (! $columnExists) {
                $skippedNoColumn[] = $table;
                continue;
            }

            // Idempotent : drop policy si elle existe puis recrée
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON \"{$table}\"");
            DB::statement("ALTER TABLE \"{$table}\" ENABLE ROW LEVEL SECURITY");
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
            $applied[] = $table;
        }

        // Insère un commentaire sur les schémas pour traçabilité (non-bloquant)
        $summary = sprintf(
            'RLS dynamic apply (Sprint 19.1) : applied=%d skipped_no_table=%d skipped_no_column=%d',
            count($applied),
            count($skippedNoTable),
            count($skippedNoColumn),
        );
        if (! empty($skippedNoTable)) {
            $summary .= ' | no_table: ' . implode(',', $skippedNoTable);
        }
        if (! empty($skippedNoColumn)) {
            $summary .= ' | no_column: ' . implode(',', $skippedNoColumn);
        }

        DB::statement('COMMENT ON SCHEMA public IS ' . DB::getPdo()->quote($summary));
    }

    public function down(): void
    {
        foreach ($this->workspaceScopedTables as $table) {
            $tableExists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = ?',
                [$table]
            );
            if (! $tableExists) {
                continue;
            }
            DB::statement("DROP POLICY IF EXISTS {$table}_workspace_isolation ON \"{$table}\"");
            // On laisse ENABLE ROW LEVEL SECURITY (pose une policy vide deny par défaut, ce qui est plus safe).
        }
    }
};
