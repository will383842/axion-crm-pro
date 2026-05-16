<?php

namespace App\Services\LLM\Mocks;

use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;

class MockLLMClient implements LLMClient
{
    public function complete(LLMRequestData $request): LLMResponseData
    {
        $fixturePath = base_path("tests/fixtures/llm/{$request->useCaseSlug}.json");
        if (file_exists($fixturePath)) {
            $data = json_decode(file_get_contents($fixturePath), true) ?: [];
            return new LLMResponseData(
                text: $data['text'] ?? json_encode($data),
                providerUsed: 'mock',
                modelUsed: 'mock-fixture',
                tokensInput: $data['tokens_input'] ?? 100,
                tokensOutput: $data['tokens_output'] ?? 50,
                costEur: 0.0,
                latencyMs: 5,
                cacheHit: false,
                requestHash: null,
                promptTemplateSlug: $request->useCaseSlug,
                promptTemplateVersion: $request->promptTemplateVersion ?? 1,
            );
        }

        return new LLMResponseData(
            text: $this->genericText($request->useCaseSlug),
            providerUsed: 'mock',
            modelUsed: 'mock-generic',
            tokensInput: 80,
            tokensOutput: 40,
            costEur: 0.0,
            latencyMs: 3,
            cacheHit: false,
            requestHash: null,
            promptTemplateSlug: $request->useCaseSlug,
            promptTemplateVersion: $request->promptTemplateVersion ?? 1,
        );
    }

    private function genericText(string $useCase): string
    {
        $payload = match ($useCase) {
            'classify_company_axion' => [
                'ia_maturity'      => ['score' => 60, 'label' => 'en_cours', 'justification' => 'mock'],
                'axion_offer_match'=> ['offer_code' => 'mission_pme', 'score' => 65, 'justification' => 'mock'],
                'priority'         => 'moyenne',
            ],
            'sector_classification'   => ['secteur_metier_axion' => 'tech', 'maturite_ia_visible' => 'en_cours'],
            'extract_team_from_page'  => [],
            'detect_email_pattern'    => ['pattern' => '{first}.{last}@{domain}', 'confidence' => 80],
            'auto_tag'                => ['tags' => ['pme', 'tech', 'fr']],
            'extract_strategic_keywords' => ['keywords' => ['ia', 'transformation']],
            default                   => ['ok' => true],
        };
        return json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}
