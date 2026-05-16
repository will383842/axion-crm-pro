import pino from 'pino';
import { getRedis } from '../bridge/redis';
import { sendResult, type ScrapeResult } from '../bridge/result-sender';
import { tickJob } from '../healthcheck-server';
import type { QueueName } from '../bridge/queues';

const log = pino({ name: 'worker-base' });

export interface ScrapeRequestJob {
  run_id: string;
  source: string;
  target_url: string | null;
  context?: Record<string, unknown>;
  company_id?: number;
  proxy_url?: string;
  user_agent?: string;
  timeout_s?: number;
  attempts?: number;
  max_attempts?: number;
}

export interface ScraperImplementation {
  scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>>;
  name: string;
}

/**
 * Worker pool sur Redis BRPOP — N consumers parallèles tirent des jobs de la liste.
 * À chaque pop : exécute scrape() avec retry exponential, envoie résultat via
 * /internal/scraper-result HMAC, tick healthcheck.
 */
export function startWorker(queue: QueueName, impl: ScraperImplementation): void {
  const concurrency = Math.max(1, Number(process.env['WORKER_CONCURRENCY'] ?? 2));
  log.info({ queue, concurrency }, 'Worker pool starting');

  for (let i = 0; i < concurrency; i++) {
    void consumeLoop(queue, impl, i);
  }
}

async function consumeLoop(queue: string, impl: ScraperImplementation, consumerId: number): Promise<void> {
  const redis = getRedis();
  for (;;) {
    try {
      const popped = await redis.brpop(queue, 30);
      if (!popped) continue;
      const [, raw] = popped;
      const job = JSON.parse(raw) as ScrapeRequestJob;
      tickJob();

      const start = Date.now();
      try {
        const out = await impl.scrape(job);
        await sendResult({
          run_id: job.run_id,
          source: job.source,
          ...out,
          latency_ms: Date.now() - start,
          fetched_at: new Date().toISOString(),
        });
      } catch (err) {
        const attempts = (job.attempts ?? 0) + 1;
        const max = job.max_attempts ?? 3;
        log.warn({ err, run_id: job.run_id, attempts, max, consumerId }, 'Job failed');

        if (attempts < max) {
          await redis.lpush(queue, JSON.stringify({ ...job, attempts }));
        } else {
          await sendResult({
            run_id: job.run_id,
            source: job.source,
            status: 'failed',
            error: err instanceof Error ? err.message : 'unknown',
            latency_ms: Date.now() - start,
            fetched_at: new Date().toISOString(),
          });
        }
      }
    } catch (err) {
      log.error({ err, consumerId }, 'Consumer loop error, retrying in 5s');
      await new Promise((r) => setTimeout(r, 5000));
    }
  }
}
