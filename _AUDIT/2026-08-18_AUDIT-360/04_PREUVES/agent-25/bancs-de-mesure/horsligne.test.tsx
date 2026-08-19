/**
 * AGENT 25 — ÉTAT HORS LIGNE.
 * Référence : main 8db8229. Aucun fichier du produit n'est modifié.
 *
 * `@tanstack/react-query` v5 a `networkMode: 'online'` par défaut, et
 * `src/main.tsx` ne le change pas : hors ligne, une requête n'est pas ÉMISE,
 * elle est MISE EN PAUSE. `isPending` reste donc VRAI, indéfiniment.
 * Question mesurée : que voit l'utilisateur ?
 */
import { describe, it, expect, afterEach } from 'vitest';
import { writeFileSync, mkdirSync } from 'node:fs';
import { onlineManager } from '@tanstack/react-query';
import type { ReactElement } from 'react';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { getJson } from '../../tests/msw/handlers';

import { UsersPage } from '@/features/users/UsersPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage';
import { ArbitragePage } from '@/features/crm-console/ArbitragePage';

const lignes: string[] = [
  'AGENT 25 — ETAT HORS LIGNE (onlineManager.setOnline(false))',
  'Reference : main 8db8229',
  '',
  'react-query v5 : networkMode="online" par defaut, non surcharge dans src/main.tsx.',
  'Hors ligne, la requete n est pas emise mais MISE EN PAUSE : isPending reste VRAI.',
  '',
];

interface Cas { nom: string; comp: () => ReactElement; path: string; url?: string; handlers: ReturnType<typeof getJson>[]; console?: 'open' }

const CAS: Cas[] = [
  { nom: '/users', comp: () => <UsersPage />, path: '/users', handlers: [getJson('/users', { data: [] })] },
  { nom: '/companies', comp: () => <CompaniesListPage />, path: '/companies', handlers: [getJson('/companies', { data: [], meta: { total: 0 } }), getJson('/referentiels/geo', { regions: [], departments: [] })] },
  { nom: '/admin/observability', comp: () => <ObservabilityPage />, path: '/admin/observability', handlers: [getJson('/observability/summary', { data: { waterfall_errors_24h: 0, hunter_quota: { used: 0, limit: 0 }, google_places_quota: { used: 0, limit: 0 }, audience_refresh_failures_24h: 0, archive_reasons: {}, recent_events: [] } })] },
  { nom: '/campaigns/$id', comp: () => <CampaignDetailPage />, path: '/campaigns/$campaignId', url: '/campaigns/k-1', handlers: [getJson('/campaigns/k-1', { data: null }), getJson('/campaigns/k-1/stats', { data: null })] },
  { nom: '/console/arbitrage', comp: () => <ArbitragePage />, path: '/console/arbitrage', handlers: [getJson('/crm/arbitrage', { data: [], meta: { total: 0 } })], console: 'open' },
];

afterEach(() => {
  onlineManager.setOnline(true);
});

describe('AGENT 25 — hors ligne', () => {
  for (const cas of CAS) {
    it(`${cas.nom} — hors ligne`, async () => {
      onlineManager.setOnline(false);
      const opts: Parameters<typeof renderScreen>[1] = {
        path: cas.path,
        url: cas.url ?? cas.path,
        handlers: cas.handlers,
      };
      if (cas.console) opts.consoleFeatures = cas.console;
      let texte = '';
      let dom = '';
      try {
        await renderScreen(cas.comp(), opts);
        await new Promise((r) => setTimeout(r, 1200));
        texte = (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();
        dom = `pulse=${document.querySelectorAll('.animate-pulse').length} ` +
              `spin=${document.querySelectorAll('.animate-spin').length} ` +
              `elements=${document.querySelectorAll('*').length}`;
      } catch (err) {
        texte = `EXCEPTION: ${(err as Error).message?.slice(0, 180)}`;
      }
      const ditHorsLigne = /hors ligne|connexion|réseau|reseau|internet|déconnect/i.test(texte);
      lignes.push(`### ${cas.nom} — HORS LIGNE`);
      lignes.push(`  DOM : ${dom}`);
      lignes.push(`  l ecran mentionne-t-il la perte de reseau ? ${ditHorsLigne ? 'OUI' : 'NON'}`);
      lignes.push(`  rendu : « ${texte.slice(0, 400)} »`);
      lignes.push('');
      expect(true).toBe(true);
    }, 40_000);
  }

  it('ZZZ — écrit le relevé hors ligne', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    writeFileSync('tmp/agent25/out/releve-horsligne.txt', lignes.join('\n'), 'utf8');
    // eslint-disable-next-line no-console
    console.log(lignes.join('\n'));
    expect(lignes.length).toBeGreaterThan(5);
  });
});
