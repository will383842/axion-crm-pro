<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * 9 use cases LLM Phase 1 v1.1 (mergés) — cf. spec/07_llm_router.md.
 */
class LlmUseCasesSeeder extends Seeder
{
    public function run(): void
    {
        // Sprint H15 (2026-05-18) — Mistral primary par défaut (politique Will : pas
        // de coût Anthropic surprise — fallback anthropic uniquement si Mistral fail).
        // Anthropic reste en fallback : si Will pose ANTHROPIC_API_KEY un jour, il
        // bénéficiera automatiquement de la robustesse multi-provider sans re-seed.
        $rows = [
            ['classify_company_axion',        'mistral',   'mistral-small-latest',   '["mistral","anthropic","openai"]', 'Classification entreprise → matching offre Axion-IA + score maturité IA'],
            ['sector_classification',         'mistral',   'mistral-small-latest',   '["mistral","anthropic"]',           'Classification secteur métier + maturité IA visible'],
            ['extract_team_from_page',        'mistral',   'mistral-small-latest',   '["mistral","anthropic","openai"]', 'Extraction noms + rôles depuis page corporate'],
            ['detect_email_pattern',          'mistral',   'mistral-small-latest',   '["mistral"]',                       'Détection pattern email entreprise (15+ variantes)'],
            ['auto_tag',                      'mistral',   'mistral-small-latest',   '["mistral","anthropic"]',           'Auto-tagging entreprise selon rules DSL'],
            ['extract_strategic_keywords',    'mistral',   'mistral-small-latest',   '["mistral","anthropic"]',           'Extraction mots-clés stratégiques du site/LinkedIn'],
            ['summarize_signals',             'mistral',   'mistral-small-latest',   '["mistral"]',                       'Synthèse signaux business (levées/news/recrutement)'],
            ['normalize_address',             'mistral',   'mistral-small-latest',   '["mistral"]',                       'Normalisation adresse vers format BAN'],
            ['classify_priority',             'mistral',   'mistral-small-latest',   '["mistral","anthropic"]',           'Classification priorité contact (chaude/tiede/froide/gelee)'],
        ];

        foreach ($rows as [$slug, $provider, $model, $fallbackJson, $desc]) {
            DB::table('llm_use_cases')->updateOrInsert(
                ['workspace_id' => null, 'slug' => $slug],
                [
                    'description'      => $desc,
                    'primary_provider' => $provider,
                    'model'            => $model,
                    'fallback_chain'   => $fallbackJson,
                    'prompt_version'   => 1,
                    'options'          => '{}',
                    'cost_cap_eur'     => 50,
                    'enabled'          => true,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ],
            );
        }
    }
}
