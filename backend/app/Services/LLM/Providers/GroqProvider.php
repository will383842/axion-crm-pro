<?php

namespace App\Services\LLM\Providers;

use Illuminate\Support\Facades\Http;

class GroqProvider
{
    private const PRICING_EUR_PER_MTOKEN = [
        'llama-3.3-70b-versatile' => ['input' => 0.59, 'output' => 0.79],
        'llama-3.1-70b-versatile' => ['input' => 0.59, 'output' => 0.79],
        'llama-3.1-8b-instant'    => ['input' => 0.05, 'output' => 0.08],
    ];

    private array $lastUsage = ['tokens_input' => 0, 'tokens_output' => 0, 'cost_eur' => 0.0];

    public function __construct(private readonly string $model) {}

    /** @param array<string,mixed> $variables @param array<string,mixed> $options */
    public function complete(string $promptTemplate, array $variables, array $options = []): string
    {
        $apiKey = (string) env('GROQ_API_KEY', '');
        if ($apiKey === '') {
            throw new \LogicException('GROQ_API_KEY not set');
        }

        $resp = Http::withToken($apiKey)
            ->timeout((int) ($options['timeout_s'] ?? 60))
            ->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => $this->model,
                'messages'    => [['role' => 'user', 'content' => $promptTemplate]],
                'temperature' => (float) ($options['temperature'] ?? 0.2),
                'max_tokens'  => (int) ($options['max_tokens'] ?? 2048),
            ]);

        if ($resp->failed()) {
            throw new \RuntimeException('Groq API error: ' . $resp->status() . ' ' . $resp->body());
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
