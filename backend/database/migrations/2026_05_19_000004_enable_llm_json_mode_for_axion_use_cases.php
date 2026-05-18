<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint H15 fix (2026-05-18) — Active le mode JSON ("response_format":"json_object")
 * sur les use cases LLM qui attendent un JSON structuré en retour.
 *
 * Sans ce flag, Mistral retourne du texte libre qui peut ou non être du JSON,
 * et LLMResponseData::asJson() retourne null silencieusement → la classification
 * n'est jamais stockée dans signals.llm_classification côté WaterfallOrchestrator
 * step10_classify.
 *
 * Use cases concernés (analyse rapide des prompts dans LLMRouterService) :
 *  - classify_company_axion       → retourne JSON {ia_maturity,...,priority}
 *  - sector_classification        → retourne JSON {sector_main,maturity}
 *  - extract_team_from_page       → retourne JSON {members: [...]}
 *  - detect_email_pattern         → retourne JSON {pattern, confidence}
 *  - auto_tag                     → retourne JSON {tags: [...]}
 *  - classify_priority            → retourne JSON {priority}
 *  - normalize_address            → retourne JSON {street, city, postcode, country}
 *  - summarize_signals            → texte libre (skip)
 *  - extract_strategic_keywords   → texte libre (skip)
 */
return new class extends Migration
{
    public function up(): void
    {
        $jsonUseCases = [
            'classify_company_axion',
            'sector_classification',
            'extract_team_from_page',
            'detect_email_pattern',
            'auto_tag',
            'classify_priority',
            'normalize_address',
        ];

        foreach ($jsonUseCases as $slug) {
            DB::table('llm_use_cases')
                ->where('slug', $slug)
                ->update([
                    'options'    => json_encode(['json' => true]),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('llm_use_cases')
            ->whereIn('slug', [
                'classify_company_axion',
                'sector_classification',
                'extract_team_from_page',
                'detect_email_pattern',
                'auto_tag',
                'classify_priority',
                'normalize_address',
            ])
            ->update([
                'options'    => '{}',
                'updated_at' => now(),
            ]);
    }
};
