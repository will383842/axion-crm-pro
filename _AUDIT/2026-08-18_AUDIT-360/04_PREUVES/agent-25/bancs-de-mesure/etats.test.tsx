/**
 * AGENT 25 — MESURE DES CINQ ÉTATS D'ÉCRAN (§5.1 points 9-13)
 * Référence : main 8db8229. Aucun fichier du produit n'est modifié.
 *
 * Chaque écran est MONTÉ POUR DE VRAI (routeur réel + MSW réseau, cf.
 * tests/helpers/renderScreen.tsx) sous quatre conditions réseau successives :
 *   EN-VOL   : la requête ne revient jamais            -> état ⏳ chargement
 *   VIDE     : 200 + collection vide                   -> état ∅ vide
 *   ERREUR   : 500                                     -> état ⚠ erreur
 *   REFUS    : 403                                     -> état ⛔ permission
 * On relève le texte du DOM et on le CLASSE. La question qui compte :
 * en ERREUR et en REFUS, l'écran affirme-t-il « 0 / aucun » ?
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { writeFileSync, mkdirSync } from 'node:fs';
import type { ReactElement } from 'react';

import { renderScreen } from '../../tests/helpers/renderScreen';
import { apiUrl } from '../../tests/msw/handlers';

import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { CompanyDetailPage } from '@/features/companies/CompanyDetailPage';
import { ContactsListPage } from '@/features/contacts/ContactsListPage';
import { MediaListPage } from '@/features/media/MediaListPage';
import { MediaDetailPage } from '@/features/media/MediaDetailPage';
import { JournalistsListPage } from '@/features/media/JournalistsListPage';
import { RoumaniePage } from '@/features/international/RoumaniePage';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { CandidatesPage } from '@/features/crm-console/CandidatesPage';
import { ArbitragePage } from '@/features/crm-console/ArbitragePage';
import { PersonTimelinePage } from '@/features/crm-console/PersonTimelinePage';
import { CampaignsListPage } from '@/features/campaigns/CampaignsListPage';
import { CampaignDetailPage } from '@/features/campaigns/CampaignDetailPage';
import { ScraperRunsPage } from '@/features/scraping/ScraperRunsPage';
import { AudiencesListPage } from '@/features/audiences/AudiencesListPage';
import { AudienceDetailPage } from '@/features/audiences/AudienceDetailPage';
import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
import { RgpdRequestsPage } from '@/features/rgpd/RgpdRequestsPage';
import { AiActRegisterPage } from '@/features/rgpd/AiActRegisterPage';
import { AuditLogsPage } from '@/features/rgpd/AuditLogsPage';
import { UsersPage } from '@/features/users/UsersPage';
import { SettingsPage } from '@/features/settings/SettingsPage';
import { TagsManagerPage } from '@/features/tags/TagsManagerPage';
import { LlmRouterPage } from '@/features/llm/LlmRouterPage';
import { ProxyProvidersPage } from '@/features/llm/ProxyProvidersPage';
import { RotationsPage } from '@/features/llm/RotationsPage';
import { ColdEmailStub } from '@/features/phase2-scaffold/ColdEmailStub';
import { LinkedInStub } from '@/features/phase2-scaffold/LinkedInStub';
import { NotFoundPage } from '@/features/misc/NotFoundPage';
import { CoveragePage } from '@/features/coverage/CoveragePage';
import { CampaignWizardPage } from '@/features/campaigns/CampaignWizardPage';
import { AudienceBuilderPage } from '@/features/audiences/AudienceBuilderPage';
import { LoginPage } from '@/features/auth/LoginPage';
import { TwoFactorPage } from '@/features/auth/TwoFactorPage';
import { MagicLinkPage } from '@/features/auth/MagicLinkPage';
import { PasswordResetPage } from '@/features/auth/PasswordResetPage';

type Condition = 'EN-VOL' | 'VIDE' | 'ERREUR' | 'REFUS';

interface Ecran {
  route: string;
  comp: () => ReactElement;
  path?: string;
  url?: string;
  /** chemin d'API -> corps « collection vide » */
  vides: Record<string, unknown>;
  console?: 'open' | 'vivier';
  horsCoquille?: boolean;
}

const VIDE_LISTE = { data: [], meta: { total: 0, current_page: 1, last_page: 1, per_page: 100 } };
const VIDE_COUNTS = { total: 0, by_relation_type: {}, by_lifecycle_stage: {} };
const VIDE_STATS = {
  companies_total: 0, companies_enriched_24h: 0, contacts_qualified: 0,
  scraper_runs_24h: 0, llm_cost_eur_month: 0,
  quality_distribution: { complete: 0, partielle: 0, basique: 0 },
  size_distribution: {}, companies_new_7d: 0, period_label: 'derniers 30 jours',
};
const VIDE_OBS = {
  data: {
    waterfall_errors_24h: 0, hunter_quota: { used: 0, limit: 0 },
    google_places_quota: { used: 0, limit: 0 }, audience_refresh_failures_24h: 0,
    archive_reasons: {}, recent_events: [],
  },
};

const ECRANS: Ecran[] = [
  { route: '/', comp: () => <DashboardPage />, vides: { '/dashboard/stats': VIDE_STATS, '/coverage': { cells: [] }, '/audit-logs': { data: [] } } },
  { route: '/companies', comp: () => <CompaniesListPage />, vides: { '/companies': VIDE_LISTE, '/referentiels/geo': { regions: [], departments: [] } } },
  { route: '/companies/$companyId', url: '/companies/c-1', comp: () => <CompanyDetailPage />, path: '/companies/$companyId', vides: { '/companies/c-1': { data: null } } },
  { route: '/contacts', comp: () => <ContactsListPage />, vides: { '/contacts': VIDE_LISTE } },
  { route: '/media', comp: () => <MediaListPage />, vides: { '/media': VIDE_LISTE } },
  { route: '/media/$mediaId', url: '/media/m-1', path: '/media/$mediaId', comp: () => <MediaDetailPage />, vides: { '/media/m-1': { data: null } } },
  { route: '/journalists', comp: () => <JournalistsListPage />, vides: { '/journalists': VIDE_LISTE } },
  { route: '/international/roumanie', comp: () => <RoumaniePage />, vides: { '/companies': VIDE_LISTE } },
  { route: '/console/contacts', comp: () => <ContactsHubPage />, console: 'open', vides: { '/crm/contacts-hub': VIDE_LISTE, '/crm/contacts-hub/counts': VIDE_COUNTS } },
  { route: '/console/vivier', comp: () => <CandidatesPage />, console: 'vivier', vides: { '/crm/candidates': VIDE_LISTE, '/crm/candidates/counts': VIDE_COUNTS } },
  { route: '/console/arbitrage', comp: () => <ArbitragePage />, console: 'open', vides: { '/crm/arbitrage': VIDE_LISTE } },
  { route: '/console/personnes/$personKey', url: '/console/personnes/p-1', path: '/console/personnes/$personKey', comp: () => <PersonTimelinePage />, console: 'open', vides: { '/crm/persons/p-1/timeline': { data: [], subjects: [] } } },
  { route: '/campaigns', comp: () => <CampaignsListPage />, vides: { '/campaigns': { data: [] } } },
  { route: '/campaigns/$campaignId', url: '/campaigns/k-1', path: '/campaigns/$campaignId', comp: () => <CampaignDetailPage />, vides: { '/campaigns/k-1': { data: null }, '/campaigns/k-1/stats': { data: null } } },
  { route: '/scraper-runs', comp: () => <ScraperRunsPage />, vides: { '/scraper-runs': VIDE_LISTE } },
  { route: '/audiences', comp: () => <AudiencesListPage />, vides: { '/audiences': { data: [] } } },
  { route: '/audiences/$audienceId', url: '/audiences/a-1', path: '/audiences/$audienceId', comp: () => <AudienceDetailPage />, vides: { '/audiences/a-1': { data: null }, '/audiences/a-1/members': { data: [] } } },
  { route: '/admin/observability', comp: () => <ObservabilityPage />, vides: { '/observability/summary': VIDE_OBS } },
  { route: '/rgpd/requests', comp: () => <RgpdRequestsPage />, vides: { '/rgpd/requests': { data: [] } } },
  { route: '/rgpd/ai-act', comp: () => <AiActRegisterPage />, vides: { '/ai-act/register': { data: [] } } },
  { route: '/audit-logs', comp: () => <AuditLogsPage />, vides: { '/audit-logs': { data: [] } } },
  { route: '/users', comp: () => <UsersPage />, vides: { '/users': { data: [] } } },
  { route: '/settings', comp: () => <SettingsPage />, vides: { '/workspace': { id: 'w1', name: '', cost_cap_eur: 0 } } },
  { route: '/tags', comp: () => <TagsManagerPage />, vides: { '/tags': { data: [] } } },
  { route: '/llm/router', comp: () => <LlmRouterPage />, vides: { '/llm/use-cases': { data: [] }, '/llm/usage/summary': { summary: { total_eur: 0, tokens_in: 0, tokens_out: 0, by_provider: {} } } } },
  { route: '/llm/proxy-providers', comp: () => <ProxyProvidersPage />, vides: { '/proxy-providers': { data: [] } } },
  { route: '/llm/rotations', comp: () => <RotationsPage />, vides: { '/rotations': { data: [] } } },
  { route: '/coverage', comp: () => <CoveragePage />, vides: { '/coverage': { cells: [] } } },
  { route: '/campaigns/new', comp: () => <CampaignWizardPage />, vides: { '/coverage': { cells: [] } } },
  { route: '/audiences/new', comp: () => <AudienceBuilderPage />, vides: {} },
  { route: '/login', comp: () => <LoginPage />, vides: {}, horsCoquille: true },
  { route: '/2fa', comp: () => <TwoFactorPage />, vides: {}, horsCoquille: true },
  { route: '/magic-link', comp: () => <MagicLinkPage />, vides: {}, horsCoquille: true },
  { route: '/password-reset', comp: () => <PasswordResetPage />, vides: {}, horsCoquille: true },
  { route: '/cold-email', comp: () => <ColdEmailStub />, vides: {} },
  { route: '/linkedin', comp: () => <LinkedInStub />, vides: {} },
  { route: '(NotFoundPage monté seul)', comp: () => <NotFoundPage />, vides: {} },
];

/** Fabrique les handlers d'une condition, pour tous les chemins de l'écran. */
function handlersPour(e: Ecran, cond: Condition) {
  return Object.entries(e.vides).map(([chemin, corps]) => {
    const url = apiUrl(chemin);
    if (cond === 'EN-VOL') return http.get(url, () => new Promise<never>(() => {}));
    if (cond === 'VIDE') return http.get(url, () => HttpResponse.json(corps as never));
    const code = cond === 'ERREUR' ? 500 : 403;
    return http.get(url, () => HttpResponse.json({ message: 'ko' } as never, { status: code }));
  });
}

const MOTS_ZERO = /\b0\b|aucun|aucune|vide|néant/i;
const MOTS_ERREUR = /impossible|erreur|échec|echec|indisponible|réessay|reessay|introuvable|pas pu|serveur/i;
const MOTS_CHARGEMENT = /chargement|chargement…|loading/i;

interface Releve { route: string; cond: Condition; texte: string; classe: string; metriques: string; }
const releves: Releve[] = [];

function metriquesDom(): string {
  const pulse = document.querySelectorAll('.animate-pulse').length;
  const spin = document.querySelectorAll('.animate-spin').length;
  const busy = document.querySelectorAll('[aria-busy="true"]').length;
  const elems = document.querySelectorAll('*').length;
  const html = document.body.innerHTML.length;
  const boutons = document.querySelectorAll('button, a[href]').length;
  return `pulse=${pulse} spin=${spin} ariaBusy=${busy} elements=${elems} html=${html}o boutons=${boutons}`;
}

function classer(texte: string, cond: Condition): string {
  const t = texte.replace(/\s+/g, ' ').trim();
  const bas = t.toLowerCase();
  const aSquelette = document.querySelectorAll('.animate-pulse').length > 0
    || document.querySelectorAll('[aria-busy="true"]').length > 0;
  const aSpinner = document.querySelectorAll('.animate-spin').length > 0;
  const domVide = document.querySelectorAll('*').length <= 6;
  const dit0 = MOTS_ZERO.test(bas);
  const ditErr = MOTS_ERREUR.test(bas);
  const ditChg = MOTS_CHARGEMENT.test(bas);
  if (t.length === 0) {
    if (aSquelette) return 'CHARGEMENT (squelette, sans texte)';
    if (aSpinner) return 'CHARGEMENT (spinner seul, sans texte)';
    return domVide ? 'ECRAN BLANC (DOM vide)' : 'ECRAN BLANC (DOM non vide, aucun texte)';
  }
  if (cond === 'EN-VOL') {
    if (aSquelette) return 'CHARGEMENT (squelette)';
    if (aSpinner) return 'CHARGEMENT (spinner)';
    if (ditChg) return 'CHARGEMENT (texte)';
    if (dit0) return '*** AFFIRME 0 PENDANT LE CHARGEMENT ***';
    return 'contenu sans marqueur de chargement';
  }
  if (ditErr && !dit0) return 'ERREUR explicite';
  if (ditErr && dit0) return 'ERREUR explicite (+ zeros affiches)';
  if (ditChg) return 'BLOQUE SUR CHARGEMENT';
  if (dit0) return cond === 'VIDE' ? 'VIDE annonce' : '*** AFFIRME 0/AUCUN ***';
  return 'contenu, sans marqueur';
}

describe('AGENT 25 — les cinq états, écran par écran', () => {
  for (const e of ECRANS) {
    for (const cond of ['EN-VOL', 'VIDE', 'ERREUR', 'REFUS'] as Condition[]) {
      it(`${e.route} | ${cond}`, async () => {
        const opts: Parameters<typeof renderScreen>[1] = {
          path: e.path ?? (e.route.startsWith('/') ? e.route : '/'),
          url: e.url ?? e.path ?? (e.route.startsWith('/') ? e.route : '/'),
          handlers: handlersPour(e, cond),
        };
        if (e.console) opts.consoleFeatures = e.console;
        if (e.horsCoquille) opts.outsideLayout = true;
        let texte = '';
        let classe = '';
        try {
          await renderScreen(e.comp(), opts);
          // laisser les requêtes se résoudre
          if (cond === 'EN-VOL') {
            await new Promise((r) => setTimeout(r, 120));
          } else {
            await waitFor(() => { expect(document.body.textContent).toBeDefined(); }, { timeout: 3000 });
            await new Promise((r) => setTimeout(r, 250));
          }
          texte = (document.body.textContent ?? '').replace(/\s+/g, ' ').trim();
          classe = classer(texte, cond);
        } catch (err) {
          texte = `EXCEPTION: ${(err as Error).message?.slice(0, 200)}`;
          classe = 'NON VERIFIABLE (exception au montage)';
        }
        releves.push({ route: e.route, cond, texte: texte.slice(0, 600), classe, metriques: metriquesDom() });
        expect(true).toBe(true);
      });
    }
  }

  it('ZZZ — écrit le relevé', () => {
    mkdirSync('tmp/agent25/out', { recursive: true });
    const lignes: string[] = [
      'AGENT 25 — RELEVE BRUT DES CINQ ETATS (montage reel, MSW reseau)',
      'Reference : main 8db8229',
      '',
    ];
    for (const r of releves) {
      lignes.push(`### ${r.route}  [${r.cond}]  => ${r.classe}`);
      lignes.push(`    DOM : ${r.metriques}`);
      lignes.push(`    « ${r.texte} »`);
      lignes.push('');
    }
    writeFileSync('tmp/agent25/out/releve-etats.txt', lignes.join('\n'), 'utf8');
    // Synthèse
    const parRoute = new Map<string, Record<string, string>>();
    for (const r of releves) {
      const cur = parRoute.get(r.route) ?? {};
      cur[r.cond] = r.classe;
      parRoute.set(r.route, cur);
    }
    const tab: string[] = ['route | EN-VOL | VIDE | ERREUR | REFUS'];
    for (const [route, cs] of parRoute) {
      tab.push(`${route} | ${cs['EN-VOL'] ?? '?'} | ${cs['VIDE'] ?? '?'} | ${cs['ERREUR'] ?? '?'} | ${cs['REFUS'] ?? '?'}`);
    }
    writeFileSync('tmp/agent25/out/synthese-etats.txt', tab.join('\n'), 'utf8');
    // eslint-disable-next-line no-console
    console.log(tab.join('\n'));
    expect(releves.length).toBeGreaterThan(0);
  });
});
