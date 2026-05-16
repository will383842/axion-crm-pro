import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { PlaywrightDirectionFinder } from './direction-finder.playwright';
import { MockDirectionFinderScraper } from '../mocks/MockDirectionFinderScraper';

export async function startDirectionFinderWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl: ScraperImplementation = useMock ? new MockDirectionFinderScraper() : new PlaywrightDirectionFinder();
  startWorker(QUEUES.DIRECTION_FINDER, impl);
}
