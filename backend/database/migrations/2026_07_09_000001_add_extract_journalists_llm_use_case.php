<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Chantier MÉDIAS (2026-07-09) — Ajoute le use case LLM `extract_journalists_from_page`
 * qui REMPLACE l'extraction regex de `journalists:scrape-ours` (fragile : 1 bon / 14)
 * par une extraction Mistral du directeur de publication / rédac chef / journalistes
 * depuis le texte des pages ours/mentions-légales/équipe d'un média.
 *
 * Politique Will (cf. LlmUseCasesSeeder) : Mistral primary (mistral-small-latest,
 * coût maîtrisé), pas de fallback Anthropic surprise. Mode JSON activé (le prompt
 * par défaut renvoie un objet {"journalists":[...]} — json_object impose une racine
 * objet). Idempotente via updateOrInsert.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('llm_use_cases')->updateOrInsert(
            ['workspace_id' => null, 'slug' => 'extract_journalists_from_page'],
            [
                'description'      => 'Extraction journalistes (dir. publication / rédac chef / journalistes) depuis pages ours/mentions-légales',
                'primary_provider' => 'mistral',
                'model'            => 'mistral-small-latest',
                'fallback_chain'   => '["mistral"]',
                'prompt_version'   => 1,
                'options'          => json_encode(['json' => true]),
                'cost_cap_eur'     => 50,
                'enabled'          => true,
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        );
    }

    public function down(): void
    {
        DB::table('llm_use_cases')
            ->whereNull('workspace_id')
            ->where('slug', 'extract_journalists_from_page')
            ->delete();
    }
};
