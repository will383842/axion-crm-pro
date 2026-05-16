<?php

namespace App\Services\LLM\Providers;

class TogetherProvider
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
        throw new \LogicException('TogetherProvider real call requires MOCK_LLM=false + Sprint 4 implementation.');
    }

    /** @return array{tokens_input:int,tokens_output:int,cost_eur:float} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }
}
