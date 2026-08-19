/**
 * AGENT 25 — ÉTAT PARTIEL : une requête passe, l'autre échoue.
 * Référence : main 8db8229. Aucun fichier du produit n'est modifié.
 *
 * Sept écrans consomment DEUX sources ou plus. Que raconte l'écran quand une
 * seule tombe ? Un écran honnête distingue « cette carte n'a pas pu être
 * chargée » de « cette carte vaut zéro ».
 */
import { describe, it, expect } from 'vitest';
import { waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { writeFileSync, mkdirSync } from 'node:fs';
import type { ReactElement } from 'react';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { apiUrl } from '../../tests/msw/handlers';

import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { CandidatesPage } from '@/features/crm-console/CandidatesPage';
import { LlmRouterPage } from '@/features/llm/LlmRouterPage';
import { AudienceDetailPage } from '@/features/audiences/AudienceDetailPage';
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';

interface Cas {
  route: string;
  comp: () => ReactElement;
  path?: string;
  url?: string;
  ok: Record<string, unknown>;   // répond 200
  ko: string[];                  // répond 500
  console?: 'open' | 'vivier';
  attendu: string;               // ce qu'un écran honnête devrait dire
}

const CAS: Cas[] = [
  {
    route: '/ (stats OK, couverture KO)',
    comp: () => <DashboardPage />,
    path: '/',
    ok: {
      '/dashboard/stats': {
        companies_total: 1234, companies_enriched_24h: 12, contacts_qualified: 40,
        scraper_runs_24h: 3, llm_cost_eur_month: 9.5,
        quality_distribution: { complete: 10, partielle: 5, basique: 2 },
        size_distribution: { pme: 10 }, companies_new_7d: 4, period_label: 'derniers 30 jours',
      },
      '/audit-logs': { data: [] },
    },
    ko: ['/coverage'],
    attendu: 'la carte « top départements » doit dire qu elle n a pas pu être chargée',
  },
  {
    route: '/console/contacts (liste OK, compteurs KO)',
    comp: () => <ContactsHubPage />,
    path: '/console/contacts',
    console: 'open',
    ok: {
      '/crm/contacts-hub': {
        data: [{ id: 'c1', denomination: 'ACME', siren: '123456789', relation_type: 'client', lifecycle_stage: 'client', person_key: null }],
        meta: { total: 1 },
      },
    },
    ko: ['/crm/contacts-hub/counts'],
    attendu: 'les onglets ne doivent pas afficher « 0 » comme un compte mesuré',
  },
  {
    route: '/console/vivier (liste OK, compteurs KO)',
    comp: () => <CandidatesPage />,
    path: '/console/vivier',
    console: 'vivier',
    ok: {
      '/crm/candidates': { data: [{ id: 'k1', denomination: 'Candidat', relation_type: 'candidat', lifecycle_stage: 'nouveau' }], meta: { total: 1 } },
    },
    ko: ['/crm/candidates/counts'],
    attendu: 'idem — les 4 vignettes À qualifier / Présélection / Entretien / Vivier',
  },
  {
    route: '/llm/router (cas d usage OK, coûts KO)',
    comp: () => <LlmRouterPage />,
    path: '/llm/router',
    ok: { '/llm/use-cases': { data: [{ id: 1, key: 'classify', provider: 'anthropic', model: 'x', fallback_chain: [] }] } },
    ko: ['/llm/usage/summary'],
    attendu: 'le coût 30 j ne doit pas afficher 0,00 € quand il n a pas pu être lu',
  },
  {
    route: '/audiences/$id (fiche OK, membres KO)',
    comp: () => <AudienceDetailPage />,
    path: '/audiences/$audienceId',
    url: '/audiences/a-1',
    ok: { '/audiences/a-1': { id: 'a-1', name: 'Audience test', members_count: 42, criteria: { all: [] }, auto_refresh: false, status: 'ready' } },
    ko: ['/audiences/a-1/members'],
    attendu: 'l onglet Membres doit dire qu il n a pas pu charger, pas « aucun membre »',
  },
  {
    route: '/campaigns/$id (fiche OK, stats KO)',
    comp: () => <CampaignDetailPage />,
    path: '/campaigns/$campaignId',
    url: '/campaigns/k-1',
    ok: { '/campaigns/k-1': { id: 'k-1', name: 'Campagne test', status: 'running', sources: [], zones: [], budget_eur: 10 } },
    ko: ['/campaigns/k-1/stats'],
    attendu: 'les compteurs de progression ne doivent pas valoir 0 par défaut',
  },
  {
    route: '/companies (liste OK, référentiel géo KO)',
    comp: () => <CompaniesListPage />,
    path: '/companies',
    ok: {
      '/companies': {
        data: [{ id: 1, siren: '123456789', denomination: 'ACME', naf: '62.01Z', size_category: 'pme', city: 'Lyon', postcode: '69000', quality_score: 50 }],
        meta: { total: 1, current_page: 1, last_page: 1, per_page: 100 },
      },
    },
    ko: ['/referentiels/geo'],
    attendu: 'les listes déroulantes région/département doivent dire pourquoi elles sont vides',
  },
];

const lignes: string[] = [
  'AGENT 25 — ETAT PARTIEL : une source repond, l autre tombe (500)',
  'Reference : main 8db8229',
  '',
];

describe('AGENT 25 — état partiel', () => {
  for (const cas of CAS) {
    it(cas.route, async () => {
      const handlers = [
        ...Object.entries(cas.ok).map(([p, b]) => http.get(apiUrl(p), () => HttpResponse.json(b as never))),
        ...cas.ko.map((p) => http.get(apiUrl(p), () => HttpResponse.json({ message: 'ko' } as never, { status: 500 }))),
      ];
      const opts: Parameters<typeof renderScreen>[1] = {
        path: cas.path ?? '/',
        url: cas.url ?? cas.path ?? '/',
        handlers,
      };
      if (cas.console) opts.consoleFeatures = cas.console;
      let texte = '';
      try {
        await renderScreen(cas.comp(), opts);
        await waitFor(() => expect(document.body.textContent).toBeDefined(), { timeout: 5000 });
        await new Promise((r) => setTimeout(r, 400));
        texte = (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();
      } catch (err) {
        texte = `EXCEPTION: ${(err as Error).message?.slice(0, 180)}`;
      }
      const ditQuelqueChose = /impossible|erreur|échec|indisponible|réessay|n'a pas pu|non chargé/i.test(texte);
      lignes.push(`### ${cas.route}`);
      lignes.push(`  source(s) en echec : ${cas.ko.join(', ')}`);
      lignes.push(`  attendu : ${cas.attendu}`);
      lignes.push(`  l ecran SIGNALE-T-IL l echec partiel ? ${ditQuelqueChose ? 'OUI' : 'NON'}`);
      lignes.push(`  rendu : « ${texte.slice(0, 500)} »`);
      lignes.push('');
      expect(true).toBe(true);
    }, 40_000);
  }

  it('ZZZ — écrit le relevé partiel', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    writeFileSync('tmp/agent25/out/releve-partiel.txt', lignes.join('\n'), 'utf8');
    // eslint-disable-next-line no-console
    console.log(lignes.join('\n'));
    expect(lignes.length).toBeGreaterThan(3);
  });
});
