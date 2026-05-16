import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { PlaywrightWebsiteScraper } from './website.playwright';
import { MockWebsiteScraper } from '../mocks/MockWebsiteScraper';

export async function startWebsiteWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl: ScraperImplementation = useMock ? new MockWebsiteScraper() : new PlaywrightWebsiteScraper();
  startWorker(QUEUES.WEBSITE, impl);
}
