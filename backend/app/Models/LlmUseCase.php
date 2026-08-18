<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Colonnes réelles de `llm_use_cases` (migration 2026_05_16_000004).
 *
 * @property int $id
 * @property ?string $workspace_id UUID — NULL = cas d'usage global
 * @property string $slug
 * @property ?string $description
 * @property string $primary_provider
 * @property string $model
 * @property array<int, string> $fallback_chain NOT NULL, défaut '[]'
 * @property int $prompt_version NOT NULL, défaut 1
 * @property array<string, mixed> $options NOT NULL, défaut '{}'
 * @property ?string $cost_cap_eur NUMERIC(10,4) — casté `decimal:4`, donc une CHAÎNE
 * @property bool $enabled
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class LlmUseCase extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id', 'slug', 'description',
        'primary_provider', 'model', 'fallback_chain',
        'prompt_version', 'options', 'cost_cap_eur',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'fallback_chain' => 'array',
            'options' => 'array',
            'enabled' => 'boolean',
            'cost_cap_eur' => 'decimal:4',
        ];
    }

    public function effectivePromptTemplate(?int $version = null): string
    {
        // Sprint 4 — chargera depuis prompt_template_versions.
        return '{}';
    }
}
