import type { ScraperImplementation, ScrapeRequestJob } from '../scrapers/base';
import type { ScrapeResult } from '../bridge/result-sender';

export class MockWebsiteScraper implements ScraperImplementation {
  public readonly name = 'mock-website';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    return {
      status: 'success',
      payload: { source: 'mock-website', target_url: req.target_url, team_pages: [] },
      emails: [],
      phones: [],
    };
  }
}
