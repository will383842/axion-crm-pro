<?php

namespace App\Services\LLM\Providers;

use Illuminate\Support\Facades\Http;

/**
 * Anthropic Claude HTTP provider — endpoint `messages` API v1.
 * Cost model 2026-05 : Sonnet 4.6 $3/MTok input, $15/MTok output ; Haiku 4.5 $0.80/MTok in, $4/MTok out.
 */
class AnthropicProvider
{
    private const PRICING_EUR_PER_MTOKEN = [
        'claude-opus-4-7'            => ['input' => 15.0, 'output' => 75.0],
        'claude-sonnet-4-6'          => ['input' => 3.0,  'output' => 15.0],
        'claude-haiku-4-5-20251001'  => ['input' => 0.80, 'output' => 4.0],
    ];

    /** @var array{tokens_input:int,tokens_output:int,cost_eur:float} */
    private array $lastUsage = ['tokens_input' => 0, 'tokens_output' => 0, 'cost_eur' => 0.0];

    public function __construct(private readonly string $model) {}

    /**
     * @param  array<string,mixed>  $variables
     * @param  array<string,mixed>  $options
     */
    public function complete(string $promptTemplate, array $variables, array $options = []): string
    {
        $apiKey = (string) env('ANTHROPIC_API_KEY', '');
        if ($apiKey === '') {
            throw new \LogicException('ANTHROPIC_API_KEY not set');
        }

        $maxTokens   = (int) ($options['max_tokens'] ?? 2048);
        $temperature = (float) ($options['temperature'] ?? 0.2);

        $resp = Http::withHeaders([
            'x-api-key'         => $apiKey,
            'anthropic-version' => '2023-06-01',
            'content-type'      => 'application/json',
        ])->timeout((int) ($options['timeout_s'] ?? 60))->post(
            'https://api.anthropic.com/v1/messages',
            [
                'model'       => $this->model,
                'max_tokens'  => $maxTokens,
                'temperature' => $temperature,
                'messages'    => [
                    ['role' => 'user', 'content' => $promptTemplate],
                ],
            ],
        );

        if ($resp->failed()) {
            throw new \RuntimeException('Anthropic API error: ' . $resp->status() . ' ' . $resp->body());
        }

        $data = $resp->json();
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $usage = $data['usage'] ?? [];
        $tokensIn  = (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = (int) ($usage['output_tokens'] ?? 0);

        $pricing = self::PRICING_EUR_PER_MTOKEN[$this->model] ?? ['input' => 0, 'output' => 0];
        // EUR ≈ USD (taux 1:1 prudent en pricing AI)
        $costEur = ($tokensIn / 1_000_000) * $pricing['input'] + ($tokensOut / 1_000_000) * $pricing['output'];

        $this->lastUsage = [
            'tokens_input'  => $tokensIn,
            'tokens_output' => $tokensOut,
            'cost_eur'      => $costEur,
        ];

        return $text;
    }

    /** @return array{tokens_input:int,tokens_output:int,cost_eur:float} */
    public function lastUsage(): array
    {
        return $this->lastUsage;
    }
}
