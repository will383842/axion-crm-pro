import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { PlaywrightPagesJaunesScraper } from './pages-jaunes.playwright';
import { MockPagesJaunesScraper } from '../mocks/MockPagesJaunesScraper';

export async function startPagesJaunesWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl: ScraperImplementation = useMock ? new MockPagesJaunesScraper() : new PlaywrightPagesJaunesScraper();
  startWorker(QUEUES.PAGES_JAUNES, impl);
}
