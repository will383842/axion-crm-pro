<?php

namespace App\Services\LLM;

use App\Contracts\LLMClient;
use App\Data\LLM\LLMRequestData;
use App\Data\LLM\LLMResponseData;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use App\Models\LlmUseCase;

/**
 * Router LLM réel — Sprint 4 implémentation détaillée.
 * Pour l'instant : stub qui dispatch vers le provider configuré côté DB (table `llm_use_cases`).
 * En MOCK_MODE=true, MockServicesProvider bind directement MockLLMClient et ce service n'est pas utilisé.
 */
class LLMRouterService implements LLMClient
{
    public function complete(LLMRequestData $request): LLMResponseData
    {
        $useCase = LlmUseCase::query()->where('slug', $request->useCaseSlug)->firstOrFail();

        $providers = $useCase->fallback_chain ?? [$useCase->primary_provider];
        $lastError = null;

        foreach ($providers as $providerSlug) {
            try {
                $client = ProviderFactory::make($providerSlug, (string) $useCase->model);
                $start  = microtime(true);
                $rawText = $client->complete(
                    $useCase->effectivePromptTemplate($request->promptTemplateVersion),
                    $request->variables,
                    (array) $useCase->options
                );
                $latencyMs = (int) ((microtime(true) - $start) * 1000);

                $usage = $client->lastUsage();

                return new LLMResponseData(
                    text: $rawText,
                    providerUsed: $providerSlug,
                    modelUsed: (string) $useCase->model,
                    tokensInput: $usage['tokens_input'] ?? 0,
                    tokensOutput: $usage['tokens_output'] ?? 0,
                    costEur: $usage['cost_eur'] ?? 0.0,
                    latencyMs: $latencyMs,
                    cacheHit: false,
                    requestHash: $request->idempotencyKey,
                    promptTemplateSlug: $useCase->slug,
                    promptTemplateVersion: $request->promptTemplateVersion ?? (int) $useCase->prompt_version,
                );
            } catch (\Throwable $e) {
                Log::warning('LLM provider failed, falling back', [
                    'use_case' => $useCase->slug,
                    'provider' => $providerSlug,
                    'error'    => $e->getMessage(),
                ]);
                $lastError = $e;
                continue;
            }
        }

        throw $lastError ?? new \RuntimeException('No LLM provider available for use case ' . $useCase->slug);
    }
}
