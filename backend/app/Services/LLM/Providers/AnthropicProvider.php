<?php

namespace App\Services\LLM\Providers;

/**
 * Anthropic Claude HTTP provider — implémenté en détail Sprint 4.
 * En MOCK_MODE=true (Sprint 1-12 par défaut), ce code n'est jamais appelé.
 */
class AnthropicProvider
{
    /** @var array{tokens_input:int,tokens_output:int,cost_eur:float} */
    private array $lastUsage = ['tokens_input' => 0, 'tokens_output' => 0, 'cost_eur' => 0.0];

    public function __construct(private readonly string $model) {}

    /**
     * @param  array<string,mixed>  $variables
     * @param  array<string,mixed>  $options
     */
    public function complete(string $promptTemplate, array $variables, array $options = []): string
    {
        throw new \LogicException('AnthropicProvider real call requires MOCK_LLM=false + Sprint 4 implementation.');
    }

    /** @return array{tokens_input:int,tokens_output:int,cost_eur:float} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }
}
