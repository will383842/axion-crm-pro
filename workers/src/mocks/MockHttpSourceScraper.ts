import type { ScraperImplementation, ScrapeRequestJob } from '../scrapers/base';
import type { ScrapeResult } from '../bridge/result-sender';

/**
 * Mock générique pour les sources API HTTP (france-travail, mesri, crunchbase, infogreffe,
 * societe-com, social-light) qui n'ont pas besoin de Playwright (juste fetch HTTP).
 * Retourne un payload symbolique cohérent avec le `source` du job.
 */
export class MockHttpSourceScraper implements ScraperImplementation {
  public readonly name: string;
  constructor(source: string) {
    this.name = `mock-${source}`;
  }

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    return {
      status: 'success',
      payload: {
        source: this.name,
        siren: req.context?.['siren'] ?? null,
        mock_data: { items: [], total: 0 },
      },
      emails: [],
      phones: [],
    };
  }
}
