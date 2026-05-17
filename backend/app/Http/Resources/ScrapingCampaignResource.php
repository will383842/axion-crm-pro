<?php

namespace App\Http\Resources;

use App\Models\ScrapingCampaign;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ScrapingCampaign
 */
class ScrapingCampaignResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'                      => $this->id,
            'workspace_id'            => $this->workspace_id,
            'created_by'              => $this->created_by,
            'name'                    => $this->name,
            'description'             => $this->description,
            'status'                  => $this->status,
            'sources'                 => $this->sources ?? [],
            'zones'                   => $this->zones ?? [],

            // Budgets
            'max_companies'           => $this->max_companies,
            'max_duration_minutes'    => $this->max_duration_minutes,
            'max_requests_per_minute' => $this->max_requests_per_minute,
            'per_source_limits'       => $this->per_source_limits,

            // Planning
            'scheduled_at'            => $this->scheduled_at,
            'expires_at'              => $this->expires_at,

            // Progression brute
            'companies_created'       => $this->companies_created,
            'requests_made'           => $this->requests_made,
            'runs_completed'          => $this->runs_completed,
            'runs_total'              => $this->runs_total,
            'duration_seconds_used'   => $this->duration_seconds_used,

            // Progression dérivée (utilisée par l'UI)
            'progress_percent'        => $this->progress_percent,
            'elapsed_minutes'         => $this->elapsed_minutes,
            'remaining_minutes'       => $this->remaining_minutes,
            'companies_remaining'     => $this->companies_remaining,

            // Lifecycle
            'started_at'              => $this->started_at,
            'paused_at'               => $this->paused_at,
            'finished_at'             => $this->finished_at,
            'paused_reason'           => $this->paused_reason,

            // Capabilities (UX : afficher/cacher boutons côté front)
            'can_start'               => $this->canStart(),
            'can_pause'               => $this->canPause(),
            'can_resume'              => $this->canResume(),
            'can_cancel'              => $this->canCancel(),

            'created_at'              => $this->created_at,
            'updated_at'              => $this->updated_at,

            // Runs nested preview (les 5 derniers)
            'runs_preview'            => $this->whenLoaded('runs', function () {
                return $this->runs->take(5)->map(fn ($r) => [
                    'id'          => $r->id,
                    'source'      => $r->source,
                    'status'      => $r->status,
                    'started_at'  => $r->started_at,
                    'finished_at' => $r->finished_at,
                    'error'       => $r->error,
                ]);
            }),
        ];
    }
}
