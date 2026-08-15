/**
 * LE MENSONGE DE L'ÉTAT VIDE.
 *
 * Défaut observé en production le 2026-08-15 : l'onglet « Prospection (base
 * froide) » affichait « Aucun contact dans cette vue » alors que la base en
 * contient 4 294 898, et le compteur de la page les affichait au même moment.
 *
 * La cause n'était ni les données ni la requête (200, 51 lignes) : c'était
 * `placeholderData`. React Query conserve les données de la vue PRÉCÉDENTE
 * pendant le chargement de la suivante, donc `isLoading` reste FAUX. Quand la
 * vue précédente est légitimement vide — « Contacts actifs » l'est tant
 * qu'aucune fiche n'a de provenance hors collecte — l'écran affirme « aucun
 * contact » pendant toute la durée de la requête suivante.
 *
 * Ces tests tiennent la réponse EN VOL pour observer précisément cet
 * intervalle : c'est le seul moment où le défaut existe, et c'est pourquoi
 * aucun test d'état stable ne pouvait l'attraper.
 */
import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
}));

const get = vi.fn<(url: string) => Promise<{ data: unknown }>>();
vi.mock('@/lib/api', () => ({ api: { get: (url: string) => get(url) } }));

const { ContactsHubPage } = await import('@/features/crm-console/ContactsHubPage');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

const COUNTS = {
  total: 4294898,
  by_relation_type: { client: 0, prospect: 4294898 },
  by_lifecycle_stage: { nouveau: 4294898 },
};

const FROIDE = {
  data: [
    {
      id: 'c1',
      siren: '123456789',
      denomination: 'ENTREPRISE FROIDE',
      relation_type: 'prospect',
      lifecycle_stage: 'nouveau',
      legal_basis: 'interet_legitime',
      city_name: 'Grenoble',
      department_code: '38',
      size_category: 'tpe',
      email_generic: null,
      updated_at: '2026-08-15T00:00:00Z',
      tags: ['src:scraping-insee'],
      contacts: [],
    },
  ],
  meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false },
};

const VIDE = { data: [], meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false } };

function renderHub() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  client.setQueryData(CONSOLE_FEATURES_KEY, {
    console_v2: true,
    universes: { business: true, vivier: false },
  });

  return render(
    <QueryClientProvider client={client}>
      <ContactsHubPage />
    </QueryClientProvider>,
  );
}

beforeEach(() => {
  get.mockReset();
});

describe('ContactsHubPage — bascule de température', () => {
  it('affiche le squelette, et NON « aucun contact », pendant le chargement de la vue froide', async () => {
    const user = userEvent.setup();

    // La vue froide est tenue en vol : on veut observer l'intervalle, pas son issue.
    let releaseFroide: (() => void) | undefined;
    const froideEnVol = new Promise<{ data: typeof FROIDE }>((resolve) => {
      releaseFroide = () => resolve({ data: FROIDE });
    });

    get.mockImplementation((url: string) => {
      if (url.includes('/counts')) return Promise.resolve({ data: COUNTS });
      if (url.includes('temperature=froids')) return froideEnVol;
      return Promise.resolve({ data: VIDE });
    });

    const { container } = renderHub();

    // État de départ : « Contacts actifs » est VRAIMENT vide, l'état vide est juste.
    expect(await screen.findByText('Aucun contact dans cette vue')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Prospection (base froide)' }));

    // ⬇️ C'est ici que vivait le défaut. Sans `isPlaceholderData`, l'état vide
    // de la vue précédente reste affiché pendant toute la requête.
    await waitFor(() => {
      expect(screen.queryByText('Aucun contact dans cette vue')).not.toBeInTheDocument();
    });
    expect(container.querySelector('[aria-hidden="true"]')).not.toBeNull();

    releaseFroide?.();

    expect(await screen.findByText('ENTREPRISE FROIDE')).toBeInTheDocument();
  });

  it('affiche l’état vide une fois la réponse revenue RÉELLEMENT vide', async () => {
    const user = userEvent.setup();
    get.mockImplementation((url: string) =>
      Promise.resolve({ data: url.includes('/counts') ? COUNTS : VIDE }),
    );

    renderHub();
    await screen.findByText('Aucun contact dans cette vue');

    await user.click(screen.getByRole('button', { name: 'Prospection (base froide)' }));

    // Le correctif ne doit pas cacher un vide LÉGITIME derrière un squelette
    // perpétuel : la réponse revenue, l'état vide reprend sa place.
    expect(await screen.findByText('Aucun contact dans cette vue')).toBeInTheDocument();
  });

  it('envoie bien le vocabulaire du back (`froids`), pas un synonyme', async () => {
    // `temperature=cold` a réellement été essayé et renvoie 422 : le vocabulaire
    // est fermé côté serveur, l'écran doit parler exactement la même langue.
    const user = userEvent.setup();
    get.mockImplementation((url: string) =>
      Promise.resolve({ data: url.includes('/counts') ? COUNTS : VIDE }),
    );

    renderHub();
    await screen.findByText('Aucun contact dans cette vue');
    await user.click(screen.getByRole('button', { name: 'Prospection (base froide)' }));

    await waitFor(() => {
      const urls = get.mock.calls.map(([u]) => String(u));
      expect(urls.some((u) => u.includes('temperature=froids'))).toBe(true);
    });
  });
});
