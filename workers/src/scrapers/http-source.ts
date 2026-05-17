import axios from 'axios';
import type { ScraperImplementation, ScrapeRequestJob } from './base';
import type { ScrapeResult } from '../bridge/result-sender';
import { ensureSsrf } from '../utils/ssrf-guard';

/**
 * Scraper générique pour les sources HTTP API (pas Playwright nécessaire) :
 *   france-travail, mesri, crunchbase, infogreffe, societe-com, social-light.
 *
 * Chaque source a son endpoint configurable via env vars + sa logique de parsing minimale.
 * Pour des extracts plus poussés, créer un scraper dédié (cf. google-maps.playwright.ts).
 */
export class HttpSourceScraper implements ScraperImplementation {
  public readonly name: string;

  constructor(private readonly source: 'france-travail' | 'mesri' | 'crunchbase' | 'infogreffe' | 'societe-com' | 'social-light') {
    this.name = `http-${source}`;
  }

  async scrape(req: ScrapeRequestJob): Promise<Omit<ScrapeResult, 'run_id' | 'source'>> {
    const siren = String(req.context?.['siren'] ?? '');
    if (!siren) {
      return { status: 'failed', payload: {}, emails: [], phones: [], error: 'missing_siren' };
    }

    const url = this.buildUrl(siren);
    await ensureSsrf(url);

    try {
      const resp = await axios.get(url, {
        timeout: 15_000,
        headers: this.buildHeaders(),
        validateStatus: (s) => s < 500,
      });

      if (resp.status === 404 || resp.status === 204) {
        return { status: 'success', payload: { source: this.source, items: [] }, emails: [], phones: [] };
      }
      if (resp.status >= 400) {
        return { status: 'failed', payload: {}, emails: [], phones: [], error: `http_${resp.status}` };
      }

      return {
        status: 'success',
        payload: { source: this.source, raw: resp.data },
        emails: [],
        phones: [],
      };
    } catch (err) {
      return {
        status: 'failed',
        payload: {},
        emails: [],
        phones: [],
        error: err instanceof Error ? err.message : 'unknown',
      };
    }
  }

  private buildUrl(siren: string): string {
    switch (this.source) {
      case 'france-travail':
        return `https://api.francetravail.io/partenaire/offresdemploi/v2/offres/search?entreprise.siren=${siren}&range=0-49`;
      case 'mesri':
        return `https://data.enseignementsup-recherche.gouv.fr/api/records/1.0/search/?dataset=fr-esr-principaux-etablissements-enseignement-superieur&q=siren:${siren}`;
      case 'crunchbase':
        return `https://api.crunchbase.com/v4/data/searches/organizations?query=${siren}`;
      case 'infogreffe':
        return `https://entreprise.api.gouv.fr/v3/infogreffe/rcs/unites_legales/${siren}/extrait_kbis`;
      case 'societe-com':
        return `https://www.societe.com/cgi-bin/search?champs=${siren}`;
      case 'social-light':
        return `https://api.example-social.com/v1/lookup?siren=${siren}`;
    }
  }

  private buildHeaders(): Record<string, string> {
    const headers: Record<string, string> = {
      'User-Agent': 'Axion-CRM-Pro/1.0 (+https://axion-crm-pro.com)',
      'Accept': 'application/json',
    };

    if (this.source === 'france-travail' && process.env['FRANCE_TRAVAIL_TOKEN']) {
      headers['Authorization'] = `Bearer ${process.env['FRANCE_TRAVAIL_TOKEN']}`;
    }
    if (this.source === 'crunchbase' && process.env['CRUNCHBASE_API_KEY']) {
      headers['X-cb-user-key'] = process.env['CRUNCHBASE_API_KEY'];
    }

    return headers;
  }
}
