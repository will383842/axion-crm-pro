import { createContext } from '../browser/launcher';
import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';
import { extractEmails, extractPhones } from '../utils/extract';

export class PlaywrightGoogleMapsScraper implements ScraperImplementation {
  public readonly name = 'playwright-google-maps';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const ctx = await createContext({
      proxyUrl: req.proxy_url,
      userAgent: req.user_agent,
      blockResources: ['image', 'media', 'font'],
    });
    const page = await ctx.newPage();

    try {
      const query = String(req.context?.['query'] ?? '');
      const url = `https://www.google.com/maps/search/${encodeURIComponent(query)}`;
      await page.goto(url, { waitUntil: 'networkidle', timeout: 30_000 });

      const consent = page.locator('button:has-text("Tout accepter")').first();
      if (await consent.isVisible({ timeout: 2_000 }).catch(() => false)) {
        await consent.click();
        await page.waitForLoadState('networkidle').catch(() => null);
      }

      const panel = page.locator('div[role="feed"]').first();
      await panel.waitFor({ timeout: 10_000 }).catch(() => null);
      for (let i = 0; i < 8; i++) {
        await panel.evaluate((el) => el.scrollBy(0, el.clientHeight)).catch(() => null);
        await page.waitForTimeout(800);
      }

      const html = await page.content();
      const places = await page.locator('div[role="feed"] > div > div[jsaction]').evaluateAll((nodes) =>
        nodes.map((n) => ({
          name:    n.querySelector('div[role="heading"]')?.textContent?.trim() ?? '',
          rating:  n.querySelector('span[aria-label*="étoile"]')?.getAttribute('aria-label') ?? null,
          address: Array.from(n.querySelectorAll('span'))
            .find((s) => /\d{5}/.test(s.textContent ?? ''))?.textContent?.trim() ?? null,
          link:    n.querySelector('a')?.getAttribute('href') ?? null,
        })),
      );

      return {
        status: 'success',
        payload: { source: 'google-maps', query, places: places.slice(0, 50) },
        emails: extractEmails(html),
        phones: extractPhones(html),
      };
    } finally {
      await ctx.close();
    }
  }
}
