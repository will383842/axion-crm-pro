<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
            'options'        => 'array',
            'enabled'        => 'boolean',
            'cost_cap_eur'   => 'decimal:4',
        ];
    }

    public function effectivePromptTemplate(?int $version = null): string
    {
        // Sprint 4 — chargera depuis prompt_template_versions.
        return '{}';
    }
}
