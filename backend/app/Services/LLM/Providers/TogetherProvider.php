<?php

namespace App\Services\LLM\Providers;

use Illuminate\Support\Facades\Http;

class TogetherProvider
{
    private const PRICING_EUR_PER_MTOKEN = [
        'meta-llama/Llama-3.3-70B-Instruct-Turbo' => ['input' => 0.88, 'output' => 0.88],
        'mistralai/Mixtral-8x22B-Instruct-v0.1'    => ['input' => 1.20, 'output' => 1.20],
    ];

    private array $lastUsage = ['tokens_input' => 0, 'tokens_output' => 0, 'cost_eur' => 0.0];

    public function __construct(private readonly string $model) {}

    /** @param array<string,mixed> $variables @param array<string,mixed> $options */
    public function complete(string $promptTemplate, array $variables, array $options = []): string
    {
        $apiKey = (string) env('TOGETHER_API_KEY', '');
        if ($apiKey === '') {
            throw new \LogicException('TOGETHER_API_KEY not set');
        }

        $resp = Http::withToken($apiKey)
            ->timeout((int) ($options['timeout_s'] ?? 60))
            ->post('https://api.together.xyz/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => [['role' => 'user', 'content' => $promptTemplate]],
                'temperature' => (float) ($options['temperature'] ?? 0.2),
                'max_tokens'  => (int) ($options['max_tokens'] ?? 2048),
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Together API error: ' . $resp->status() . ' ' . $resp->body());
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
