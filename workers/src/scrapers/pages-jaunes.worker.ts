import { QUEUES } from '../bridge/queues';
import { startWorker, type ScraperImplementation } from './base';
import { PlaywrightPagesJaunesScraper } from './pages-jaunes.playwright';
import { MockPagesJaunesScraper } from '../mocks/MockPagesJaunesScraper';
import { useMockScrapers } from '../config/mocks';

export async function startPagesJaunesWorker(): Promise<void> {
  // C18-017 — la règle du drapeau vit dans `../config/mocks`, une seule fois,
  // au lieu d'être recopiée mot pour mot dans les onze workers.
  const useMock = useMockScrapers();
  const impl: ScraperImplementation = useMock ? new MockPagesJaunesScraper() : new PlaywrightPagesJaunesScraper();
  startWorker(QUEUES.PAGES_JAUNES, impl);
}
