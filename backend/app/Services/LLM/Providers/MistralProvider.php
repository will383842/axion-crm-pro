<?php

namespace App\Services\LLM\Providers;

use Illuminate\Support\Facades\Http;

class MistralProvider
{
    private const PRICING_EUR_PER_MTOKEN = [
        'mistral-large-latest'  => ['input' => 2.0,  'output' => 6.0],
        'mistral-small-latest'  => ['input' => 0.20, 'output' => 0.60],
        'open-mistral-7b'       => ['input' => 0.25, 'output' => 0.25],
        'open-mixtral-8x22b'    => ['input' => 2.0,  'output' => 6.0],
    ];

    private array $lastUsage = ['tokens_input' => 0, 'tokens_output' => 0, 'cost_eur' => 0.0];

    public function __construct(private readonly string $model) {}

    /** @param array<string,mixed> $variables @param array<string,mixed> $options */
    public function complete(string $promptTemplate, array $variables, array $options = []): string
    {
        $apiKey = (string) env('MISTRAL_API_KEY', '');
        if ($apiKey === '') {
            throw new \LogicException('MISTRAL_API_KEY not set');
        }

        // Sprint H15 fix (2026-05-18) — Mistral API v1 refuse response_format=null
        // avec un 422 ("Input should be a valid dictionary or object"). On
        // n'inclut le paramètre QUE quand JSON mode est demandé.
        $payload = [
            'model'       => $this->model,
            'messages'    => [['role' => 'user', 'content' => $promptTemplate]],
            'temperature' => (float) ($options['temperature'] ?? 0.2),
            'max_tokens'  => (int) ($options['max_tokens'] ?? 2048),
        ];
        if (! empty($options['json'])) {
            $payload['response_format'] = ['type' => 'json_object'];
        }

        $resp = Http::withToken($apiKey)
            ->timeout((int) ($options['timeout_s'] ?? 60))
            ->post('https://api.mistral.ai/v1/chat/completions', $payload);

        if ($resp->failed()) {
            throw new \RuntimeException('Mistral API error: ' . $resp->status() . ' ' . $resp->body());
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
