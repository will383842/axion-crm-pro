<?php

namespace App\Services\LLM\Providers;

use Illuminate\Support\Facades\Http;

class OpenAIProvider
{
    private const PRICING_EUR_PER_MTOKEN = [
        'gpt-4o'        => ['input' => 2.50, 'output' => 10.0],
        'gpt-4o-mini'   => ['input' => 0.15, 'output' => 0.60],
        'gpt-4-turbo'   => ['input' => 10.0, 'output' => 30.0],
    ];

    private array $lastUsage = ['tokens_input' => 0, 'tokens_output' => 0, 'cost_eur' => 0.0];

    public function __construct(private readonly string $model) {}

    /** @param  array<string,mixed>  $variables @param array<string,mixed> $options */
    public function complete(string $promptTemplate, array $variables, array $options = []): string
    {
        $apiKey = (string) env('OPENAI_API_KEY', '');
        if ($apiKey === '') {
            throw new \LogicException('OPENAI_API_KEY not set');
        }

        $resp = Http::withToken($apiKey)
            ->timeout((int) ($options['timeout_s'] ?? 60))
            ->post('https://api.openai.com/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => [['role' => 'user', 'content' => $promptTemplate]],
                'temperature' => (float) ($options['temperature'] ?? 0.2),
                'max_tokens'  => (int) ($options['max_tokens'] ?? 2048),
                'response_format' => isset($options['json']) && $options['json'] ? ['type' => 'json_object'] : null,
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('OpenAI API error: ' . $resp->status() . ' ' . $resp->body());
        }
        $data = $resp->json();
        $text = (string) ($data['choices'][0]['message']['content'] ?? '');
        $usage = $data['usage'] ?? [];
        $tokensIn  = (int) ($usage['prompt_tokens'] ?? 0);
        $tokensOut = (int) ($usage['completion_tokens'] ?? 0);
        $pricing = self::PRICING_EUR_PER_MTOKEN[$this->model] ?? ['input' => 0, 'output' => 0];

        $this->lastUsage = [
            'tokens_input'  => $tokensIn,
            'tokens_output' => $tokensOut,
            'cost_eur'      => ($tokensIn / 1_000_000) * $pricing['input'] + ($tokensOut / 1_000_000) * $pricing['output'],
        ];
        return $text;
    }

    public function lastUsage(): array { return $this->lastUsage; }
}
