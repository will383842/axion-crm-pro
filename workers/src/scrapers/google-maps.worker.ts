import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { MockGoogleMapsScraper } from '../mocks/MockGoogleMapsScraper';
import { PlaywrightGoogleMapsScraper } from './google-maps.playwright';
import { useMockScrapers } from '../config/mocks';

export async function startGoogleMapsWorker(): Promise<void> {
  // C18-017 — la règle du drapeau vit dans `../config/mocks`, une seule fois,
  // au lieu d'être recopiée mot pour mot dans les onze workers.
  const useMock = useMockScrapers();
  const impl: ScraperImplementation = useMock ? new MockGoogleMapsScraper() : new PlaywrightGoogleMapsScraper();
  startWorker(QUEUES.GOOGLE_MAPS, impl);
}
