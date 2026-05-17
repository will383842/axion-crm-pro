<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Tag
 */
class TagResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'slug'            => $this->slug,
            'name'            => $this->name,
            'color'           => $this->color,
            'category'        => $this->category ?? 'custom',
            'kind'            => $this->kind ?? 'manual',
            'description'     => $this->description,
            'companies_count' => $this->companies_count ?? 0,
            'created_at'      => optional($this->created_at)?->toIso8601String(),
            'updated_at'      => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
