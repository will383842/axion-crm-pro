<?php

namespace App\Http\Resources;

use App\Crm\Campagnes\ReglageDestinataires;
use App\Models\EmailAudience;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EmailAudience
 */
class EmailAudienceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workspace_id' => $this->workspace_id,
            'name' => $this->name,
            'description' => $this->description,
            'criteria' => $this->criteria ?? [],
            'is_active' => (bool) $this->is_active,
            'auto_refresh' => (bool) $this->auto_refresh,
            'member_count' => (int) $this->member_count,
            'refreshed_at' => optional($this->refreshed_at)?->toIso8601String(),
            // À qui écrire dans chaque organisation (2026-09-30).
            'destinataires' => $this->resource instanceof EmailAudience
                ? ReglageDestinataires::deLAudience($this->resource)->enTableau()
                : (new ReglageDestinataires)->enTableau(),
            'created_by' => $this->created_by,
            'created_at' => optional($this->created_at)?->toIso8601String(),
            'updated_at' => optional($this->updated_at)?->toIso8601String(),
        ];
    }
}
