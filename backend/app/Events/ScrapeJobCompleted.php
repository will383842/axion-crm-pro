<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Sprint 18.3 — broadcast event lorsqu'un scraper run termine.
 * Émis par App\Jobs\ScraperRun ou par App\Http\Controllers\Internal\ScraperResultController.
 */
class ScrapeJobCompleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $workspaceId,
        public readonly int $scraperRunId,
        public readonly string $status,         // 'success' | 'failed' | 'partial'
        public readonly int $companiesCreated,
        public readonly int $companiesUpdated,
        public readonly ?string $errorMessage = null,
    ) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel('workspace.' . $this->workspaceId)];
    }

    public function broadcastAs(): string
    {
        return 'scrape-job.completed';
    }

    public function broadcastWith(): array
    {
        return [
            'scraper_run_id'    => $this->scraperRunId,
            'status'            => $this->status,
            'companies_created' => $this->companiesCreated,
            'companies_updated' => $this->companiesUpdated,
            'error_message'     => $this->errorMessage,
            'occurred_at'       => now()->toIso8601String(),
        ];
    }
}
