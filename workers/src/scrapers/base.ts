import { Worker, type Job } from 'bullmq';
import pino from 'pino';
import { getRedis } from '../bridge/redis';
import { sendResult, type ScrapeResult } from '../bridge/result-sender';
import type { QueueName } from '../bridge/queues';

const log = pino({ name: 'worker-base' });

export interface ScrapeRequestJob {
  run_id: string;
  source: string;
  target_url: string;
  context?: Record<string, unknown>;
  company_id?: number;
  proxy_url?: string;
  user_agent?: string;
  timeout_s?: number;
}

export interface ScraperImplementation {
  scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>>;
  name: string;
}

export function startWorker(queue: QueueName, impl: ScraperImplementation): Worker {
  const concurrency = Number(process.env['WORKER_CONCURRENCY'] ?? 2);

  const worker = new Worker<ScrapeRequestJob>(
    queue,
    async (job: Job<ScrapeRequestJob>) => {
      const start = Date.now();
      try {
        const out = await impl.scrape(job.data);
        await sendResult({
          run_id: job.data.run_id,
          source: job.data.source,
          ...out,
          latency_ms: Date.now() - start,
          fetched_at: new Date().toISOString(),
        });
      } catch (err) {
        log.error({ err, job_id: job.id, run_id: job.data.run_id, queue }, 'Job failed');
        await sendResult({
          run_id: job.data.run_id,
          source: job.data.source,
          status: 'failed',
          error: err instanceof Error ? err.message : 'unknown',
          latency_ms: Date.now() - start,
          fetched_at: new Date().toISOString(),
        });
        throw err;
      }
    },
    { connection: getRedis(), concurrency },
  );

  worker.on('ready', () => log.info({ queue, concurrency }, 'Worker ready'));
  worker.on('failed', (job, err) => log.warn({ job_id: job?.id, err }, 'Job failed'));

  return worker;
}
