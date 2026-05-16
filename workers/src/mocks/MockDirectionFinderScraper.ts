import type { ScraperImplementation, ScrapeRequestJob } from '../scrapers/base';
import type { ScrapeResult } from '../bridge/result-sender';

export class MockDirectionFinderScraper implements ScraperImplementation {
  public readonly name = 'mock-direction-finder';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    return {
      status: 'success',
      payload: {
        source:       'mock-direction-finder',
        company_name: req.context?.['company_name'] ?? 'Mock Corp',
        domain:       req.context?.['domain'] ?? 'mock.fr',
        c_level: [
          { name: 'Mock CEO', title: 'CEO', sources: ['/about'], confidence: 80 },
          { name: 'Mock CFO', title: 'CFO', sources: ['/team'],  confidence: 70 },
        ],
      },
      emails: [],
      phones: [],
    };
  }
}
