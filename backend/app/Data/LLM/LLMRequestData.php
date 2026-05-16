<?php

namespace App\Data\LLM;

use Spatie\LaravelData\Data;

class LLMRequestData extends Data
{
    /**
     * @param  array<string,mixed>  $variables
     * @param  array<string,mixed>  $options
     */
    public function __construct(
        public string $useCaseSlug,
        public array $variables = [],
        public ?int $promptTemplateVersion = null,
        public array $options = [],
        public ?string $idempotencyKey = null,
    ) {}
}
