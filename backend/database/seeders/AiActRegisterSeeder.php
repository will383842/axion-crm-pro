<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * AI Act register (UE 2024/1689) — documente nos systèmes IA + classification risque.
 * Phase 1 : 1 système (LLM Router) + 0 high-risk pour scoring B2B intérêt légitime.
 */
class AiActRegisterSeeder extends Seeder
{
    public function run(): void
    {
        $workspaceId = DB::table('workspaces')->where('slug', 'axion-ia')->value('id');
        if (! $workspaceId) {
            return;
        }

        DB::table('ai_act_register')->updateOrInsert(
            ['workspace_id' => $workspaceId, 'system_name' => 'LLM Router — Classification Axion-IA'],
            [
                'purpose'        => 'Classification entreprise (maturité IA, matching offre Axion-IA, priorité) à partir de données publiques B2B (INSEE, site web). Aucune décision automatique impactant la personne ; output utilisé en suggestion pour opérateur humain.',
                'risk_class'     => 'limited',
                'provider'       => 'Anthropic + Mistral (fallback)',
                'model'          => 'claude-sonnet-4-6 / mistral-large-latest',
                'dpia_url'       => null,
                'impact_assessment' => json_encode([
                    'data_categories' => ['raison sociale', 'NAF', 'effectif INSEE', 'site web public'],
                    'no_pii'          => false,
                    'human_oversight' => 'systematic',
                    'opt_out_route'   => '/rgpd/requests (type=opposition)',
                    'mitigations'     => [
                        'cost_cap_eur monthly kill-switch',
                        'sanitize ext_ prompt-injection guard',
                        'audit_logs hash chain',
                    ],
                    'review_date'     => '2027-05-17',
                ], JSON_UNESCAPED_UNICODE),
                'created_at'     => now(),
                'updated_at'     => now(),
            ],
        );
    }
}
