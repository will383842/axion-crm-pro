import { createContext } from '../browser/launcher';
import * as cheerio from 'cheerio';
import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';
import { extractEmails, extractPhones } from '../utils/extract';

const TEAM_PATHS = ['/equipe', '/team', '/about', '/a-propos', '/notre-equipe', '/qui-sommes-nous', '/contact'];

export class PlaywrightWebsiteScraper implements ScraperImplementation {
  public readonly name = 'playwright-website';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const ctx = await createContext({
      proxyUrl: req.proxy_url,
      userAgent: req.user_agent,
      blockResources: ['image', 'media', 'font'],
    });
    const page = await ctx.newPage();

    try {
      const base = new URL(req.target_url);
      const visited = new Set<string>();
      const allEmails = new Set<string>();
      const allPhones = new Set<string>();
      const teamPages: { url: string; html: string }[] = [];

      const candidatePaths = ['/', ...TEAM_PATHS];
      for (const path of candidatePaths) {
        const url = new URL(path, base).toString();
        if (visited.has(url)) continue;
        visited.add(url);

        try {
          const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 15_000 });
          if (!resp || resp.status() >= 400) continue;

          const html = await page.content();
          extractEmails(html).forEach((e) => allEmails.add(e));
          extractPhones(html).forEach((p) => allPhones.add(p));

          if (TEAM_PATHS.some((p) => path.startsWith(p))) {
            teamPages.push({ url, html: html.slice(0, 50_000) });
          }
        } catch {
          // 404 / DNS / timeout — skip
        }
      }

      const $ = cheerio.load(await page.content().catch(() => ''));
      const linkedin = $('a[href*="linkedin.com/company/"]').first().attr('href') ?? null;

      return {
        status: allEmails.size > 0 || teamPages.length > 0 ? 'success' : 'partial',
        payload: {
          source:      'website',
          base:        base.toString(),
          visited:     Array.from(visited),
          linkedin_url:linkedin,
          team_pages:  teamPages,
        },
        emails: Array.from(allEmails),
        phones: Array.from(allPhones),
      };
    } finally {
      await ctx.close();
    }
  }
}
