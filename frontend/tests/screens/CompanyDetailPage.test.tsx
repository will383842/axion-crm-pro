/**
 * ÉCRAN `/companies/$companyId` — `src/features/companies/CompanyDetailPage.tsx`.
 *
 * Famille : écran de DÉTAIL — paramètre de route, panneaux dépliables,
 * mutation d'écriture.
 *
 * Ce que ce fichier apporte et qu'un mock de module n'aurait pas pu :
 *  - « Enrichir maintenant » fait un `api.post`, donc passe par `ensureCsrf()`.
 *    Ici le cookie Sanctum est réellement demandé avant l'écriture.
 *  - Le 404 : `useParams` + `isError` doivent produire un écran honnête, pas
 *    une page en chargement perpétuel.
 *
 * ⚠️ L'écran lit `useParams({ strict: false })` : il ne DÉCLARE pas son
 * identifiant de route. Un paramètre mal nommé dans `routeTree.tsx` le laisserait
 * silencieusement à `undefined` (et `enabled: !!companyId` couperait la requête
 * sans rien afficher). Le premier test ci-dessous vérifie l'identifiant
 * jusqu'à l'URL requêtée, ce qui referme ce trou.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CompanyDetailPage } from '@/features/companies/CompanyDetailPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, getStatus, recordGet, recordPost } from '../msw/handlers';

const PATH = '/companies/$companyId';
const URL_VISITEE = '/companies/42';

const FICHE = {
  id: 42,
  siren: '812345678',
  denomination: 'ACME GRENOBLE',
  naf: '62.01Z',
  naf_label: 'Programmation informatique',
  legal_form: 'SAS',
  effectif_range: '10 à 19 salariés',
  size_category: 'tpe',
  address: '12 rue des Alpes',
  postcode: '38000',
  city: 'Grenoble',
  department: '38',
  website: 'https://acme-grenoble.fr',
  phone: '04 76 00 00 00',
  linkedin_url: null,
  quality_score: 78,
  quality_breakdown: { email: 30, phone: 20, website: 20, contact: 8 },
  priority: 'haute',
  signals: { insee: { ok: true }, site: { emails: 2 } },
  created_at: '2024-03-01T00:00:00Z',
  enriched_at: '2026-08-10T09:15:00Z',
  contacts: [],
};

const LANDING = [{ path: '/companies' }];

describe('CompanyDetailPage — rendu', () => {
  it('charge la fiche de l’entreprise DE L’URL et affiche son identité légale', async () => {
    const { handler, urls } = recordGet('/companies/42', FICHE);

    await renderScreen(<CompanyDetailPage />, {
      path: PATH,
      url: URL_VISITEE,
      landingRoutes: LANDING,
      handlers: [handler],
    });

    expect(await screen.findByRole('heading', { name: 'ACME GRENOBLE' })).toBeVisible();
    expect(new URL(urls[0] as string).pathname).toBe('/api/v1/companies/42');

    expect(screen.getByText('SIREN 812345678')).toBeVisible();
    expect(screen.getByText('SAS')).toBeVisible();
    expect(screen.getByText('Programmation informatique')).toBeVisible();
    expect(screen.getByText('12 rue des Alpes, 38000, Grenoble')).toBeVisible();
    expect(screen.getByRole('link', { name: 'https://acme-grenoble.fr' })).toHaveAttribute(
      'target',
      '_blank',
    );
  });

  it('le panneau « Données brutes » est REPLIÉ à l’arrivée', async () => {
    await renderScreen(<CompanyDetailPage />, {
      path: PATH,
      url: URL_VISITEE,
      landingRoutes: LANDING,
      handlers: [getJson('/companies/42', FICHE)],
    });

    await screen.findByRole('heading', { name: 'ACME GRENOBLE' });
    const bascule = screen.getByRole('button', { name: /Données brutes/ });
    expect(bascule).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByText(/"insee"/)).not.toBeInTheDocument();
  });

  it('404 : écran honnête, pas un chargement perpétuel', async () => {
    await renderScreen(<CompanyDetailPage />, {
      path: PATH,
      url: '/companies/99999',
      landingRoutes: LANDING,
      handlers: [getStatus('/companies/99999', 404)],
    });

    expect(await screen.findByText('Cette entreprise n\'existe pas ou a été supprimée.')).toBeVisible();
    expect(screen.queryByText('Chargement de la fiche entreprise…')).not.toBeInTheDocument();
  });
});

describe('CompanyDetailPage — parcours', () => {
  it('déplier « Données brutes » RÉVÈLE les signaux, replier les cache', async () => {
    const user = userEvent.setup();
    await renderScreen(<CompanyDetailPage />, {
      path: PATH,
      url: URL_VISITEE,
      landingRoutes: LANDING,
      handlers: [getJson('/companies/42', FICHE)],
    });

    await screen.findByRole('heading', { name: 'ACME GRENOBLE' });
    const bascule = screen.getByRole('button', { name: /Données brutes/ });

    await user.click(bascule);

    expect(bascule).toHaveAttribute('aria-expanded', 'true');
    // Le JSON des signaux apparaît RÉELLEMENT — et il vient de la réponse.
    expect(screen.getByText(/"insee"/)).toBeVisible();
    expect(screen.getByText(/"emails": 2/)).toBeVisible();

    await user.click(bascule);

    expect(bascule).toHaveAttribute('aria-expanded', 'false');
    expect(screen.queryByText(/"insee"/)).not.toBeInTheDocument();
  });

  it('« Enrichir maintenant » POSTe (cookie CSRF compris) puis RECHARGE la fiche', async () => {
    const user = userEvent.setup();
    const lecture = recordGet('/companies/42', FICHE);
    const ecriture = recordPost('/companies/42/enrich', { queued: true });

    await renderScreen(<CompanyDetailPage />, {
      path: PATH,
      url: URL_VISITEE,
      landingRoutes: LANDING,
      handlers: [lecture.handler, ecriture.handler],
    });

    await screen.findByRole('heading', { name: 'ACME GRENOBLE' });
    expect(lecture.urls).toHaveLength(1);

    // Deux boutons portent ce libellé (en-tête + colonne « Actions rapides ») —
    // c'est voulu côté produit ; on prend celui de l'en-tête.
    const enTete = screen.getByRole('heading', { name: 'ACME GRENOBLE' }).closest('header');
    await user.click(
      within(enTete as HTMLElement).getByRole('button', { name: 'Enrichir maintenant' }),
    );

    await waitFor(() => {
      expect(ecriture.bodies).toHaveLength(1);
    });

    // `onSuccess` invalide `['company', companyId]` : la fiche est REDEMANDÉE.
    // Sans ça, l'écran afficherait éternellement l'état d'avant l'enrichissement.
    await waitFor(() => {
      expect(lecture.urls.length).toBeGreaterThan(1);
    });
  });

  it('le fil d’Ariane ramène à la liste des entreprises', async () => {
    const user = userEvent.setup();
    const view = await renderScreen(<CompanyDetailPage />, {
      path: PATH,
      url: URL_VISITEE,
      landingRoutes: LANDING,
      handlers: [getJson('/companies/42', FICHE)],
    });

    await screen.findByRole('heading', { name: 'ACME GRENOBLE' });
    await user.click(screen.getByRole('link', { name: 'Entreprises' }));

    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/companies');
    });
    expect(screen.getByTestId('landing')).toHaveAttribute('data-path', '/companies');
  });
});
