/**
 * ÉCRAN `/console/contacts` — `src/features/crm-console/ContactsHubPage.tsx`.
 *
 * Famille : console v2, sous `ConsoleGate`.
 *
 * ⚠️ Il existe DÉJÀ `tests/components/ContactsHubPage.test.tsx`, écrit autour
 * d'un défaut précis (le mensonge de l'état vide dû à `placeholderData`). On ne
 * le remplace pas : il documente un incident et le tient. Ce fichier-ci couvre
 * ce qu'un mock de module ne POUVAIT pas voir —
 *
 *  - les URLs RÉELLEMENT émises par axios, query string comprise (l'autre
 *    fichier reçoit l'argument de `api.get`, pas la requête HTTP) ;
 *  - le gardien `ConsoleGate` dans ses trois états ;
 *  - les deux filtres pays / statut de prospection, que l'API acceptait déjà
 *    et que l'écran ne proposait pas.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ContactsHubPage } from '@/features/crm-console/ContactsHubPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet } from '../msw/handlers';

const PATH = '/console/contacts';

const COUNTS = {
  total: 4_294_898,
  by_relation_type: { client: 12, prospect: 4_294_886 },
  by_lifecycle_stage: { nouveau: 4_294_886, opportunite: 5, dormant: 900 },
};

const LIGNE = {
  id: 3,
  siren: '123456789',
  denomination: 'ACME GRENOBLE',
  relation_type: 'client',
  lifecycle_stage: 'nouveau',
  legal_basis: 'contrat',
  city_name: 'Grenoble',
  department_code: '38',
  size_category: 'tpe',
  email_generic: null,
  updated_at: '2026-08-01T00:00:00Z',
  tags: ['sect:btp', 'geo:38'],
  contacts: [],
};

const PAGE = {
  data: [LIGNE],
  meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false },
};

const LANDING = [{ path: '/console/personnes/$personKey' }];

/** La vignette KPI portant ce libellé — et non l'onglet du même nom. */
function vignette(label: string): HTMLElement {
  const cible = screen
    .getAllByText(label)
    .find((el) => el.closest('[role="tablist"]') === null);
  if (cible === undefined) throw new Error(`Aucune vignette « ${label} » hors des onglets.`);
  // `.rounded-2xl` est la racine de `KpiCard` ; `closest('div')` s'arrêterait à
  // la ligne du libellé, qui ne contient PAS la valeur.
  const carte = cible.closest('.rounded-2xl');
  if (carte === null) throw new Error(`Vignette « ${label} » sans conteneur.`);
  return carte as HTMLElement;
}

describe('ContactsHubPage — rendu', () => {
  it('affiche les compteurs, les onglets de type et la première page de fiches', async () => {
    await renderScreen(<ContactsHubPage />, {
      path: PATH,
      consoleFeatures: 'open',
      landingRoutes: LANDING,
      handlers: [getJson('/crm/contacts-hub/counts', COUNTS), getJson('/crm/contacts-hub', PAGE)],
    });

    expect(await screen.findByText('ACME GRENOBLE')).toBeVisible();
    expect(screen.getByRole('heading', { name: 'Contacts' })).toBeVisible();

    // Le sous-titre nomme le périmètre de température : il est AFFICHÉ, jamais
    // implicite — c'est le parti-pris §3a, et il porte tout l'écran.
    expect(screen.getByText('Tous les types — Contacts actifs')).toBeVisible();

    // Les vignettes lisent bien `by_relation_type` / `by_lifecycle_stage`,
    // et non `total` : quatre chiffres distincts, pas quatre fois le même.
    // ⚠️ « Clients » existe DEUX fois à l'écran — vignette et onglet. On écarte
    // explicitement l'onglet, sinon la requête est ambiguë.
    expect(vignette('Clients')).toHaveTextContent('12');
    expect(vignette('Dormants')).toHaveTextContent('900');

    // Les tags gouvernés sont traduits, pas affichés bruts.
    expect(screen.getByText('Secteur · btp')).toBeVisible();
    expect(screen.queryByText('sect:btp')).not.toBeInTheDocument();

    // Les trois périmètres de température sont proposés, « actifs » actif.
    expect(screen.getByRole('button', { name: 'Contacts actifs' })).toHaveAttribute(
      'aria-pressed',
      'true',
    );
    expect(screen.getByRole('button', { name: 'Prospection (base froide)' })).toHaveAttribute(
      'aria-pressed',
      'false',
    );
  });

  it('drapeau console FERMÉ : rien de la console, et AUCUN appel métier', async () => {
    const counts = recordGet('/crm/contacts-hub/counts', COUNTS);
    const liste = recordGet('/crm/contacts-hub', PAGE);

    await renderScreen(<ContactsHubPage />, {
      path: PATH,
      consoleFeatures: 'closed',
      landingRoutes: LANDING,
      handlers: [counts.handler, liste.handler],
    });

    expect(await screen.findByText('Console non activée')).toBeVisible();
    expect(screen.queryByRole('heading', { name: 'Contacts' })).not.toBeInTheDocument();
    // L'inertie n'est pas un message : c'est l'ABSENCE de requête. L'API
    // répond 404 quand le drapeau est fermé ; un écran qui appelle quand même
    // et affiche « erreur » n'est pas inerte, il est cassé.
    expect(counts.urls).toEqual([]);
    expect(liste.urls).toEqual([]);
  });

  it('drapeau INCONNU : muet et fermé — pas d’affirmation « non activée »', async () => {
    // `consoleFeatures` non fourni ⇒ le cache n'est pas semé, `/config/features`
    // est réellement interrogé. Tant qu'il n'a pas répondu, on ne sait rien.
    const { container } = await renderScreen(<ContactsHubPage />, {
      path: PATH,
      landingRoutes: LANDING,
      handlers: [getJson('/crm/contacts-hub/counts', COUNTS), getJson('/crm/contacts-hub', PAGE)],
    });

    expect(screen.queryByText('Console non activée')).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Contacts' })).not.toBeInTheDocument();
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();
  });
});

describe('ContactsHubPage — parcours', () => {
  it('basculer sur la base froide REQUÊTE `temperature=froids` et change le périmètre affiché', async () => {
    const user = userEvent.setup();
    const { handler, urls } = recordGet('/crm/contacts-hub', PAGE);

    await renderScreen(<ContactsHubPage />, {
      path: PATH,
      consoleFeatures: 'open',
      landingRoutes: LANDING,
      handlers: [getJson('/crm/contacts-hub/counts', COUNTS), handler],
    });

    await screen.findByText('ACME GRENOBLE');
    expect(new URL(urls[0] as string).searchParams.get('temperature')).toBe('actifs');

    await user.click(screen.getByRole('button', { name: 'Prospection (base froide)' }));

    await waitFor(() => {
      expect(urls.length).toBeGreaterThan(1);
    });
    const derniere = new URL(urls[urls.length - 1] as string);
    // `froids`, et pas un synonyme : `temperature=cold` renvoie 422 côté API.
    expect(derniere.searchParams.get('temperature')).toBe('froids');

    // Et l'écran DIT où l'on se trouve.
    expect(await screen.findByText('Tous les types — Prospection (base froide)')).toBeVisible();
  });

  it('choisir un onglet de type ajoute `relation_type` à la requête', async () => {
    const user = userEvent.setup();
    const { handler, urls } = recordGet('/crm/contacts-hub', PAGE);

    await renderScreen(<ContactsHubPage />, {
      path: PATH,
      consoleFeatures: 'open',
      landingRoutes: LANDING,
      handlers: [getJson('/crm/contacts-hub/counts', COUNTS), handler],
    });

    await screen.findByText('ACME GRENOBLE');
    expect(new URL(urls[0] as string).searchParams.has('relation_type')).toBe(false);

    const onglets = screen.getByRole('tablist');
    await user.click(within(onglets).getByRole('tab', { name: /Clients/ }));

    await waitFor(() => {
      const derniere = new URL(urls[urls.length - 1] as string);
      expect(derniere.searchParams.get('relation_type')).toBe('client');
    });
  });

  it('le filtre statut de prospection part bien en `filter[prospection_status]`', async () => {
    // Ce filtre existait côté API (`CompanyQueryFilters`) sans être proposé à
    // l'écran : les fiches concernées restaient introuvables depuis la console.
    const user = userEvent.setup();
    const { handler, urls } = recordGet('/crm/contacts-hub', PAGE);

    await renderScreen(<ContactsHubPage />, {
      path: PATH,
      consoleFeatures: 'open',
      landingRoutes: LANDING,
      handlers: [getJson('/crm/contacts-hub/counts', COUNTS), handler],
    });

    await screen.findByText('ACME GRENOBLE');
    const select = screen.getByLabelText('Filtre statut de prospection');
    const option = within(select).getAllByRole('option')[1] as HTMLOptionElement;

    await user.selectOptions(select, option.value);

    await waitFor(() => {
      const derniere = new URL(urls[urls.length - 1] as string);
      expect(derniere.searchParams.get('filter[prospection_status]')).toBe(option.value);
    });
  });
});
