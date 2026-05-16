import type { ScraperImplementation, ScrapeRequestJob } from '../scrapers/base';
import type { ScrapeResult } from '../bridge/result-sender';

export class MockSearchScraper implements ScraperImplementation {
  public readonly name = 'mock-google-search';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const query = String(req.context?.['query'] ?? 'mock');
    return {
      status: 'success',
      payload: {
        source: 'mock-google-search',
        query,
        results: [
          { rank: 1, title: 'Mock LinkedIn — Company', url: 'https://www.linkedin.com/company/mock', snippet: 'Mock snippet.' },
        ],
      },
      emails: [],
      phones: [],
    };
  }
}
