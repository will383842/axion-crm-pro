<?php

namespace App\Services\LLM;

use App\Services\LLM\Providers\AnthropicProvider;
use App\Services\LLM\Providers\OpenAIProvider;
use App\Services\LLM\Providers\MistralProvider;
use App\Services\LLM\Providers\GroqProvider;
use App\Services\LLM\Providers\TogetherProvider;

class ProviderFactory
{
    public static function make(string $provider, string $model): object
    {
        return match ($provider) {
            'anthropic' => new AnthropicProvider($model),
            'openai'    => new OpenAIProvider($model),
            'mistral'   => new MistralProvider($model),
            'groq'      => new GroqProvider($model),
            'together'  => new TogetherProvider($model),
            default     => throw new \InvalidArgumentException("Unknown LLM provider: $provider"),
        };
    }
}
