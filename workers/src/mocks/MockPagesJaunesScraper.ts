import type { ScraperImplementation, ScrapeRequestJob } from '../scrapers/base';
import type { ScrapeResult } from '../bridge/result-sender';

export class MockPagesJaunesScraper implements ScraperImplementation {
  public readonly name = 'mock-pages-jaunes';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    return {
      status: 'success',
      payload: { source: 'mock-pages-jaunes', query: req.context?.['query'] ?? '', cards: [] },
      emails: [],
      phones: [],
    };
  }
}
