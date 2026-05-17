import { QUEUES } from '../bridge/queues';
import { startWorker } from './base';
import { HttpSourceScraper } from './http-source';
import { MockHttpSourceScraper } from '../mocks/MockHttpSourceScraper';

export async function startSocieteComWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl = useMock ? new MockHttpSourceScraper('societe-com') : new HttpSourceScraper('societe-com');
  startWorker(QUEUES.SOCIETE_COM, impl);
}
