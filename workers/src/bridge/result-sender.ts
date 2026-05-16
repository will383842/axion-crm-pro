import axios from 'axios';
import { createHmac } from 'node:crypto';
import pino from 'pino';

const log = pino({ name: 'result-sender' });

export interface ScrapeResult {
  run_id: string;
  source: string;
  status: 'success' | 'failed' | 'partial';
  payload?: Record<string, unknown>;
  emails?: string[];
  phones?: string[];
  error?: string;
  latency_ms?: number;
  fetched_at?: string;
}

export async function sendResult(result: ScrapeResult): Promise<void> {
  const url = process.env['WORKER_INTERNAL_RESULT_URL'] ?? 'http://api/internal/scraper-result';
  const secret = process.env['WORKER_INTERNAL_HMAC_SECRET'] ?? '';
  const body = JSON.stringify(result);
  const sig = createHmac('sha256', secret).update(body).digest('hex');

  try {
    await axios.post(url, body, {
      headers: { 'Content-Type': 'application/json', 'X-Worker-Signature': sig },
      timeout: 10_000,
    });
  } catch (err) {
    log.error({ err, run_id: result.run_id }, 'Failed to POST scrape result');
    throw err;
  }
}
