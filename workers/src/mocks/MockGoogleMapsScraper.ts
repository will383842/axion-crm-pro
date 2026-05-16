import { readFileSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
import type { ScraperImplementation, ScrapeRequestJob } from '../scrapers/base';
import type { ScrapeResult } from '../bridge/result-sender';
import { extractEmails, extractPhones } from '../utils/extract';

export class MockGoogleMapsScraper implements ScraperImplementation {
  public readonly name = 'mock-google-maps';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const fixturePath = resolve(process.cwd(), 'tests/fixtures/google-maps/default.html');
    const html = existsSync(fixturePath)
      ? readFileSync(fixturePath, 'utf-8')
      : '<html><body>mock google maps response</body></html>';

    return {
      status: 'success',
      payload: { html_preview: html.slice(0, 200), context: req.context ?? {} },
      emails: extractEmails(html),
      phones: extractPhones(html),
    };
  }
}
