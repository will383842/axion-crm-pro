/**
 * AGENT 42 — la recherche à la frappe déclenche-t-elle une requête par touche ?
 *
 * Critère 1 du CDC : « résultats à la frappe, moins de 5 s ».
 * On tape un mot dans le champ de recherche de trois écrans, et on COMPTE les
 * requêtes HTTP réellement émises (MSW enregistre les URLs).
 *
 * ⚠️ Borne : MSW répond instantanément. En production (constat A-010, `php -S`,
 * un seul processus), ces requêtes sont SÉRIALISÉES : elles s'empilent.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { ContactsListPage } from '@/features/contacts/ContactsListPage';
import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { GlobalSearch } from '@/components/ui/GlobalSearch';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson } from '../msw/handlers';

const MOT = 'boulangerie'; // 11 caractères

function compteur(path: string, body: unknown) {
  const urls: string[] = [];
  const handler = http.get(apiUrl(path), ({ request }) => {
    urls.push(new URL(request.url).search);
    return HttpResponse.json(body as never);
  });
  return { handler, urls };
}

const CONTACTS = { data: [], meta: { total: 0 } };
const COMPANIES = { data: [], meta: { total: 0, last_page: 1 } };
const HUB = { data: [], meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false } };
const COUNTS = {
  total: 0,
  by_relation_type: { client: 0, prospect: 0 },
  by_lifecycle_stage: { nouveau: 0, opportunite: 0, dormant: 0 },
};

describe('AGENT 42 — anti-rebond de la recherche', () => {
  it('ContactsListPage : combien de requêtes pour 11 frappes ?', async () => {
    const c = compteur('/contacts', CONTACTS);
    await renderScreen(<ContactsListPage />, { path: '/contacts', handlers: [c.handler] });
    // `SearchInput` de cet écran porte le libellé « Nom de famille… ».
    const champ = await screen.findByPlaceholderText(/Nom de famille/i);
    const avant = c.urls.length;
    await userEvent.type(champ, MOT);
    await waitFor(() => expect(c.urls.length).toBeGreaterThan(avant), { timeout: 10_000 });
    // On laisse retomber d'éventuels rebonds tardifs.
    await new Promise((r) => setTimeout(r, 1500));
    console.info(
      `[FRAPPE] ContactsListPage — ${MOT.length} touches → ${c.urls.length - avant} requêtes après la 1re :`,
      JSON.stringify(c.urls.slice(avant)),
    );
    expect(c.urls.length).toBeGreaterThan(0);
  }, 120_000);

  it('ContactsHubPage : combien de requêtes pour 11 frappes ?', async () => {
    const c = compteur('/crm/contacts-hub', HUB);
    await renderScreen(<ContactsHubPage />, {
      path: '/console/contacts',
      consoleFeatures: 'open',
      landingRoutes: [{ path: '/console/personnes/$personKey' }],
      handlers: [getJson('/crm/contacts-hub/counts', COUNTS), c.handler],
    });
    await screen.findAllByPlaceholderText(/Rechercher|Nom|Raison/i);
    const avant = c.urls.length;
    const champ = screen.getAllByRole('textbox')[0]!;
    await userEvent.type(champ, MOT);
    await waitFor(() => expect(c.urls.length).toBeGreaterThan(avant), { timeout: 10_000 });
    await new Promise((r) => setTimeout(r, 1500));
    console.info(
      `[FRAPPE] ContactsHubPage — ${MOT.length} touches → ${c.urls.length - avant} requêtes après la 1re :`,
      JSON.stringify(c.urls.slice(avant)),
    );
    expect(c.urls.length).toBeGreaterThan(0);
  }, 120_000);

  it('CompaniesListPage : combien de requêtes pour 11 frappes ?', async () => {
    const c = compteur('/companies', COMPANIES);
    await renderScreen(<CompaniesListPage />, {
      path: '/companies',
      handlers: [c.handler, getJson('/referentiels/geo', { regions: [], departments: [] })],
    });
    await screen.findAllByPlaceholderText(/Rechercher/i);
    const avant = c.urls.length;
    const champ = screen.getAllByPlaceholderText(/Rechercher/i)[0]!;
    await userEvent.type(champ, MOT);
    await waitFor(() => expect(c.urls.length).toBeGreaterThan(avant), { timeout: 10_000 });
    await new Promise((r) => setTimeout(r, 1500));
    console.info(
      `[FRAPPE] CompaniesListPage — ${MOT.length} touches → ${c.urls.length - avant} requêtes après la 1re :`,
      JSON.stringify(c.urls.slice(avant)),
    );
    expect(c.urls.length).toBeGreaterThan(0);
  }, 120_000);

  it('GlobalSearch (⌘K) : combien de requêtes pour 11 frappes ?', async () => {
    const c = compteur('/search', { companies: [], contacts: [], tags: [] });
    await renderScreen(<GlobalSearch />, { path: '/', handlers: [c.handler] });
    await userEvent.click(screen.getByRole('button', { name: /Recherche globale/i }));
    const champ = await screen.findByPlaceholderText(/Rechercher entreprise/i);
    const avant = c.urls.length;
    await userEvent.type(champ, MOT);
    await waitFor(() => expect(c.urls.length).toBeGreaterThan(avant), { timeout: 10_000 });
    await new Promise((r) => setTimeout(r, 1500));
    console.info(
      `[FRAPPE] GlobalSearch — ${MOT.length} touches → ${c.urls.length - avant} requêtes après la 1re :`,
      JSON.stringify(c.urls.slice(avant)),
    );
    expect(c.urls.length).toBeGreaterThan(0);
  }, 120_000);

  /**
   * TÉMOIN NÉGATIF — l'instrument sait-il voir un anti-rebond quand il y en a un ?
   * `AudienceBuilderPage` en porte un, de 500 ms (`AudienceBuilderPage.tsx:170`).
   * Si le compteur y rend « beaucoup moins de requêtes que de touches », alors
   * les chiffres ci-dessus ne sont pas un artefact de la méthode.
   */
  it('TÉMOIN NÉGATIF — AudienceBuilderPage, qui LUI porte un anti-rebond de 500 ms', async () => {
    const { AudienceBuilderPage } = await import('@/features/audiences/AudienceBuilderPage');
    const c = compteur('/audiences/preview', { count: 0, sample: [] });
    const cPost = { urls: [] as string[] };
    const postHandler = http.post(apiUrl('/audiences/preview'), async ({ request }) => {
      cPost.urls.push(await request.text());
      return HttpResponse.json({ count: 0, sample: [] } as never);
    });
    await renderScreen(<AudienceBuilderPage />, {
      path: '/audiences/new',
      handlers: [c.handler, postHandler, getJson('/tags', { data: [] })],
      landingRoutes: ['/audiences'],
    });
    // Le champ « slugs de tags » est CELUI qui alimente les critères, donc
    // l'aperçu : taper ailleurs (le nom de l'audience) ne prouverait rien.
    const champ = await screen.findByPlaceholderText(/decisionnaire/i);
    const avant = cPost.urls.length;
    await userEvent.type(champ, MOT);
    await new Promise((r) => setTimeout(r, 2000));
    console.info(
      `[TÉMOIN] AudienceBuilderPage — ${MOT.length} touches → ${cPost.urls.length - avant} aperçus POST`,
    );
    expect(cPost.urls.length - avant).toBeLessThan(MOT.length);
  }, 120_000);
});
