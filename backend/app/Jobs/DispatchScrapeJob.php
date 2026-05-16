<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Redis;

/**
 * Job laravel-side qui dépose un payload sur une liste Redis simple lue côté Node.
 *
 * Convention queue name : `axion:scrape:<source>` (cf. workers/src/bridge/queues.ts).
 * Côté Node : `BRPOP axion:scrape:<source>` (cf. workers/src/scrapers/base.ts).
 *
 * NOTE : on n'utilise pas le format binaire BullMQ natif (hashes + sets) car la
 * passerelle PHP→Node serait fragile. Schéma simple JSON + listes Redis = robuste,
 * inspectable via `redis-cli`, supporte n'importe quel langage côté consumer.
 */
class DispatchScrapeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1; // ce job ne fait que push ; le worker Node gère ses propres retries

    /** @param array<string,mixed> $context */
    public function __construct(
        public readonly int $companyId,
        public readonly string $source,
        public readonly array $context = [],
        public readonly ?string $targetUrl = null,
    ) {}

    public function handle(): void
    {
        $payload = [
            'run_id'     => bin2hex(random_bytes(8)),
            'source'     => $this->source,
            'company_id' => $this->companyId,
            'target_url' => $this->targetUrl,
            'context'    => $this->context,
            'enqueued_at'=> now()->toIso8601String(),
            'attempts'   => 0,
            'max_attempts'=> 3,
        ];

        Redis::connection('queue')->lpush(
            "axion:scrape:{$this->source}",
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );
    }
}
