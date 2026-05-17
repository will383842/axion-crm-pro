<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 18.3 — broadcast lorsqu'une entreprise vient d'être enrichie (LLM, email finder, etc.).
 */
class CompanyEnriched implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly int $companyId,
        public readonly string $companyName,
        public readonly int $newQualityScore,
        public readonly array $fieldsEnriched = [],
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'company.enriched';
    }

    public function broadcastWith(): array
    {
        return [
            'company_id'        => $this->companyId,
            'company_name'      => $this->companyName,
            'new_quality_score' => $this->newQualityScore,
            'fields_enriched'   => $this->fieldsEnriched,
            'occurred_at'       => now()->toIso8601String(),
        ];
    }
}
