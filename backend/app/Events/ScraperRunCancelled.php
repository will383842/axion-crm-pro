<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 19.6 — broadcast event lorsqu'un scraper run est annulé via API.
 * Émis par App\Http\Controllers\Api\ScraperRunsController::cancel.
 *
 * Les workers vérifient également le flag Redis `cancelled:scraper-run:{id}`
 * (TTL 1h) pour interrompre les tâches déjà en cours.
 */
class ScraperRunCancelled implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly int $scraperRunId,
        public readonly ?string $reason = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'scrape-job.cancelled';
    }

    public function broadcastWith(): array
    {
        return [
            'scraper_run_id' => $this->scraperRunId,
            'reason'         => $this->reason,
            'occurred_at'    => now()->toIso8601String(),
        ];
    }
}
