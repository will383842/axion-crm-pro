<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sprint H15 (2026-05-18) — Bascule les 3 use cases LLM ayant Anthropic comme
 * provider primaire vers Mistral, car Will n'a pas posé ANTHROPIC_API_KEY
 * et veut éviter le 1er essai Anthropic qui throw (LogicException : no key)
 * puis fallback Mistral. Plus propre + log plus clean.
 *
 * Anthropic reste en fallback chain au cas où Will poserait sa key plus tard.
 *
 * Idempotente : on UPDATE conditionnellement seulement les rows dont
 * primary_provider='anthropic' aujourd'hui.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['slug' => 'classify_company_axion',     'fallback' => '["mistral","anthropic","openai"]'],
            ['slug' => 'extract_team_from_page',     'fallback' => '["mistral","anthropic","openai"]'],
            ['slug' => 'extract_strategic_keywords', 'fallback' => '["mistral","anthropic"]'],
        ];

        foreach ($rows as $r) {
            DB::table('llm_use_cases')
                ->where('slug', $r['slug'])
                ->where('primary_provider', 'anthropic')
                ->update([
                    'primary_provider' => 'mistral',
                    'model'            => 'mistral-small-latest',
                    'fallback_chain'   => $r['fallback'],
                    'updated_at'       => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Reverse : remet Anthropic primary (utile si Will pose ANTHROPIC_API_KEY un jour)
        $rows = [
            ['slug' => 'classify_company_axion',     'model' => 'claude-sonnet-4-6',           'fallback' => '["anthropic","mistral","openai"]'],
            ['slug' => 'extract_team_from_page',     'model' => 'claude-haiku-4-5-20251001',   'fallback' => '["anthropic","openai"]'],
            ['slug' => 'extract_strategic_keywords', 'model' => 'claude-haiku-4-5-20251001',   'fallback' => '["anthropic"]'],
        ];

        foreach ($rows as $r) {
            DB::table('llm_use_cases')
                ->where('slug', $r['slug'])
                ->where('primary_provider', 'mistral')
                ->update([
                    'primary_provider' => 'anthropic',
                    'model'            => $r['model'],
                    'fallback_chain'   => $r['fallback'],
                    'updated_at'       => now(),
                ]);
        }
    }
};
