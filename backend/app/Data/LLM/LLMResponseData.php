<?php

namespace App\Data\LLM;

use Spatie\LaravelData\Data;

class LLMResponseData extends Data
{
    public function __construct(
        public string $text,
        public string $providerUsed,
        public string $modelUsed,
        public int $tokensInput = 0,
        public int $tokensOutput = 0,
        public float $costEur = 0.0,
        public int $latencyMs = 0,
        public bool $cacheHit = false,
        public ?string $requestHash = null,
        public ?string $promptTemplateSlug = null,
        public ?int $promptTemplateVersion = null,
    ) {}

    /** @return array<string,mixed>|null */
    public function asJson(): ?array
    {
        $decoded = json_decode($this->text, true);
        return is_array($decoded) ? $decoded : null;
    }
}
