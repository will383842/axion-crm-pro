import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';

export class PlaywrightGoogleMapsScraper implements ScraperImplementation {
  public readonly name = 'playwright-google-maps';

  async scrape(_req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    throw new Error('PlaywrightGoogleMapsScraper requires MOCK_SCRAPERS=false + Sprint 6 implementation.');
  }
}
