<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Job laravel-side qui dépose un payload sur la queue Redis lue côté Node (BullMQ).
 * Convention noms : `scrape:<source>` (cf. workers/src/bridge/queues.ts).
 */
class DispatchScrapeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly int $companyId,
        public readonly string $source,
    ) {}

    public function handle(): void
    {
        // Le payload est lu par worker Node via BullMQ. La queue name est passée au dispatch.
        $payload = [
            'run_id'     => bin2hex(random_bytes(8)),
            'source'     => $this->source,
            'company_id' => $this->companyId,
            'target_url' => null,
            'context'    => [],
        ];
        \Redis::lpush("bull:scrape:{$this->source}:waiting", json_encode([
            'data'      => $payload,
            'opts'      => ['attempts' => 3, 'backoff' => ['type' => 'exponential', 'delay' => 5000]],
            'timestamp' => (int) (microtime(true) * 1000),
        ]));
    }
}
