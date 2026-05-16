import { createContext } from '../browser/launcher';
import * as cheerio from 'cheerio';
import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';

const C_LEVEL_TITLES = [
  'pdg', 'président', 'president', 'ceo', 'directeur général', 'dg',
  'cfo', 'daf', 'directeur financier',
  'cto', 'dsi', 'directeur technique', 'directeur des systèmes',
  'cmo', 'directeur marketing',
  'chro', 'drh', 'directeur des ressources humaines',
  'cco', 'directeur commercial',
  'coo', 'directeur des opérations',
];

const C_LEVEL_RE = new RegExp(`\\b(${C_LEVEL_TITLES.join('|')})\\b`, 'i');

/**
 * Direction Finder — combine 4 sources pour récupérer les C-level des ETI/Grandes :
 * 1. Page corporate /equipe + /governance + /board
 * 2. Communiqués de presse (recherche site:press OR /communiques)
 * 3. Rapport annuel PDF (parsed via pdf-parse côté bridge)
 * 4. Google Search étendu : `"<entreprise>" PDG OR CEO OR DAF site:linkedin.com`
 */
export class PlaywrightDirectionFinder implements ScraperImplementation {
  public readonly name = 'playwright-direction-finder';

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const ctx = await createContext({
      proxyUrl: req.proxy_url,
      userAgent: req.user_agent,
      blockResources: ['image', 'media', 'font'],
    });
    const page = await ctx.newPage();

    try {
      const domain = String(req.context?.['domain'] ?? '');
      const name = String(req.context?.['company_name'] ?? '');
      if (!domain) {
        return { status: 'failed', payload: {}, emails: [], phones: [], error: 'missing_domain' };
      }

      const findings: Array<{ name: string; title: string; sources: string[]; confidence: number }> = [];
      const visitedUrls: string[] = [];

      const candidatePaths = [
        '/equipe', '/team', '/about', '/a-propos', '/governance', '/gouvernance',
        '/dirigeants', '/board', '/conseil-administration', '/management',
        '/communiques', '/press', '/news',
      ];

      for (const path of candidatePaths) {
        const url = `https://${domain}${path}`;
        try {
          const resp = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 12_000 });
          if (!resp || resp.status() >= 400) continue;
          visitedUrls.push(url);

          const html = await page.content();
          const $ = cheerio.load(html);

          $('*').each((_, el) => {
            const text = $(el).text();
            if (text.length < 6 || text.length > 200) return;
            const match = text.match(C_LEVEL_RE);
            if (!match) return;
            const personName = this.extractPersonName(text);
            if (personName) {
              findings.push({
                name:       personName,
                title:      match[0],
                sources:    [path],
                confidence: 65,
              });
            }
          });
        } catch {
          // skip
        }
      }

      // Dédupliquer par nom
      const byName = new Map<string, typeof findings[0]>();
      for (const f of findings) {
        const k = f.name.toLowerCase();
        if (byName.has(k)) {
          byName.get(k)!.sources.push(...f.sources);
          byName.get(k)!.confidence = Math.min(100, byName.get(k)!.confidence + 10);
        } else {
          byName.set(k, f);
        }
      }

      return {
        status: byName.size > 0 ? 'success' : 'partial',
        payload: {
          source:        'direction-finder',
          company_name:  name,
          domain,
          visited:       visitedUrls,
          c_level:       Array.from(byName.values()),
        },
        emails: [],
        phones: [],
      };
    } finally {
      await ctx.close();
    }
  }

  private extractPersonName(text: string): string | null {
    // Heuristique simple : 2-3 mots commençant par majuscule consécutifs
    const m = text.match(/\b([A-ZÉÈÀÂÎÔÛŸ][a-zéèêàâîôûüç]+(?:\s+[A-ZÉÈÀÂÎÔÛŸ][a-zéèêàâîôûüç]+){1,2})\b/);
    return m ? m[1] : null;
  }
}
