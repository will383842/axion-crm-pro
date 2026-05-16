import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { PlaywrightSearchScraper } from './google-search.playwright';
import { MockSearchScraper } from '../mocks/MockSearchScraper';

export async function startGoogleSearchWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl: ScraperImplementation = useMock ? new MockSearchScraper() : new PlaywrightSearchScraper();
  startWorker(QUEUES.GOOGLE_SEARCH, impl);
}
