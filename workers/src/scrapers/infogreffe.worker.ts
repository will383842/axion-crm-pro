import { QUEUES } from '../bridge/queues';
import { startWorker } from './base';
import { HttpSourceScraper } from './http-source';
import { MockHttpSourceScraper } from '../mocks/MockHttpSourceScraper';

export async function startInfogreffeWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl = useMock ? new MockHttpSourceScraper('infogreffe') : new HttpSourceScraper('infogreffe');
  startWorker(QUEUES.INFOGREFFE, impl);
}
