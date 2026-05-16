import { createContext } from '../browser/launcher';
import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';
import { extractEmails, extractPhones } from '../utils/extract';

export class PlaywrightPagesJaunesScraper implements ScraperImplementation {
  public readonly name = 'playwright-pages-jaunes';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const ctx = await createContext({
      proxyUrl: req.proxy_url,
      userAgent: req.user_agent,
      blockResources: ['image', 'media', 'font'],
    });
    const page = await ctx.newPage();

    try {
      const query = String(req.context?.['query'] ?? '');
      const where = String(req.context?.['where'] ?? '');
      const url = `https://www.pagesjaunes.fr/recherche/${encodeURIComponent(where || 'France')}/${encodeURIComponent(query)}`;
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });

      await page.locator('button[data-pjbutton="accept"]').first().click({ timeout: 3_000 }).catch(() => null);

      const cards = await page.locator('li.bi').evaluateAll((nodes) =>
        nodes.slice(0, 50).map((n) => ({
          name:    n.querySelector('a.denomination-links')?.textContent?.trim() ?? '',
          address: n.querySelector('a.address')?.textContent?.trim() ?? '',
          phone:   n.querySelector('.coord-numero')?.textContent?.trim() ?? null,
          link:    n.querySelector('a.denomination-links')?.getAttribute('href') ?? null,
        })),
      );

      const html = await page.content();
      return {
        status: 'success',
        payload: { source: 'pages-jaunes', query, where, cards },
        emails: extractEmails(html),
        phones: extractPhones(html),
      };
    } finally {
      await ctx.close();
    }
  }
}
