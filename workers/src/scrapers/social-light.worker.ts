import { QUEUES } from '../bridge/queues';
import { startWorker } from './base';
import { HttpSourceScraper } from './http-source';
import { MockHttpSourceScraper } from '../mocks/MockHttpSourceScraper';
import { useMockScrapers } from '../config/mocks';

export async function startSocialLightWorker(): Promise<void> {
  // C18-017 — la règle du drapeau vit dans `../config/mocks`, une seule fois,
  // au lieu d'être recopiée mot pour mot dans les onze workers.
  const useMock = useMockScrapers();
  const impl = useMock ? new MockHttpSourceScraper('social-light') : new HttpSourceScraper('social-light');
  startWorker(QUEUES.SOCIAL_LIGHT, impl);
}
