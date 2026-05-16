import { createContext } from '../browser/launcher';
import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';

/**
 * Google Search Wrapper — remplace PhantomBuster.
 * Stratégie : rotation Google/Bing/DuckDuckGo + proxy résidentiel + captcha solver.
 */
export class PlaywrightSearchScraper implements ScraperImplementation {
  public readonly name = 'playwright-google-search';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const ctx = await createContext({
      proxyUrl: req.proxy_url,
      userAgent: req.user_agent,
      blockResources: ['image', 'media', 'font', 'stylesheet'],
    });
    const page = await ctx.newPage();

    try {
      const query = String(req.context?.['query'] ?? '');
      const engine = String(req.context?.['engine'] ?? 'duckduckgo');
      const url = this.buildUrl(engine, query);

      await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 30_000 });

      // Détection captcha
      const captchaPresent = await page.locator('iframe[src*="recaptcha"], #captcha-form').first().isVisible({ timeout: 2_000 }).catch(() => false);
      if (captchaPresent) {
        return {
          status: 'failed',
          payload: { source: 'google-search', query, engine, captcha: true },
          emails: [],
          phones: [],
          error: 'captcha_required',
        };
      }

      const results = await this.extractResults(page, engine);
      return {
        status: results.length > 0 ? 'success' : 'partial',
        payload: { source: 'google-search', query, engine, results },
        emails: [],
        phones: [],
      };
    } finally {
      await ctx.close();
    }
  }

  private buildUrl(engine: string, query: string): string {
    switch (engine) {
      case 'google':     return `https://www.google.com/search?q=${encodeURIComponent(query)}&num=20&hl=fr`;
      case 'bing':       return `https://www.bing.com/search?q=${encodeURIComponent(query)}&count=20`;
      case 'duckduckgo': return `https://duckduckgo.com/?q=${encodeURIComponent(query)}&kl=fr-fr`;
      default:           return `https://duckduckgo.com/?q=${encodeURIComponent(query)}`;
    }
  }

  private async extractResults(page: import('playwright').Page, engine: string): Promise<unknown[]> {
    const selectors: Record<string, { item: string; title: string; link: string; snippet: string }> = {
      google:     { item: 'div.g',          title: 'h3', link: 'a',              snippet: 'div[data-sncf]' },
      bing:       { item: 'li.b_algo',      title: 'h2', link: 'h2 a',           snippet: 'p' },
      duckduckgo: { item: 'article[data-testid="result"]', title: 'h2', link: 'h2 a', snippet: '[data-result="snippet"]' },
    };
    const sel = selectors[engine] ?? selectors.duckduckgo;
    return page.locator(sel.item).evaluateAll((nodes, { sel }) =>
      nodes.slice(0, 20).map((n, i) => ({
        rank:    i + 1,
        title:   n.querySelector(sel.title)?.textContent?.trim() ?? '',
        url:     n.querySelector(sel.link)?.getAttribute('href') ?? '',
        snippet: n.querySelector(sel.snippet)?.textContent?.trim() ?? '',
      })),
      { sel },
    );
  }
}
