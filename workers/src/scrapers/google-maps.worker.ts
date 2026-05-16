import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { MockGoogleMapsScraper } from '../mocks/MockGoogleMapsScraper';
import { PlaywrightGoogleMapsScraper } from './google-maps.playwright';

export async function startGoogleMapsWorker(): Promise<void> {
  const useMock = (process.env['MOCK_SCRAPERS'] ?? process.env['MOCK_MODE'] ?? 'true') !== 'false';
  const impl: ScraperImplementation = useMock ? new MockGoogleMapsScraper() : new PlaywrightGoogleMapsScraper();
  startWorker(QUEUES.GOOGLE_MAPS, impl);
}
