/**
 * LA PAGE CONTACTS NE DOIT PLUS LAISSER DE REQUÊTES ORPHELINES — 2026-10-02.
 *
 * Constat en production : la liste du hub tournait plus de 100 s côté serveur,
 * sans limite de durée. Chaque visite en relançait une, et quitter la page ne
 * l'arrêtait pas : React Query n'annule une requête que si son `queryFn`
 * CONSOMME le `signal` qu'il reçoit — et celui du hub ne le lisait pas. Les
 * requêtes s'empilaient, l'onglet gelait, la session se perdait.
 *
 * Deux gardes :
 *   1. quitter la page ABANDONNE la requête en vol (le signal transmis à axios
 *      passe à `aborted`) ;
 *   2. des compteurs en échec ne masquent PLUS les lignes — avant, un échec
 *      des compteurs remplaçait tout l'écran par l'erreur.
 */
import type { ReactNode } from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AxiosError, AxiosHeaders } from 'axios';
import type * as ModuleApi from '@/lib/api';

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children }: { children: ReactNode }) => <span>{children}</span>,
}));

type Config = { signal?: AbortSignal } | undefined;
const get = vi.fn<(url: string, config?: Config) => Promise<{ data: unknown }>>();
vi.mock('@/lib/api', async (importOriginal) => {
  const reel = await importOriginal<typeof ModuleApi>();
  return { ...reel, api: { get: (url: string, config?: Config) => get(url, config) } };
});

const { ContactsHubPage } = await import('@/features/crm-console/ContactsHubPage');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

const PAGE = {
  data: [
    {
      id: 1,
      siren: '123456789',
      denomination: 'FICHE ACTIVE',
      relation_type: 'client',
      lifecycle_stage: 'client',
      legal_basis: 'contract',
      city_name: 'Lyon',
      department_code: '69',
      size_category: 'tpe',
      email_generic: null,
      updated_at: '2026-10-02T00:00:00Z',
      tags: [],
      contacts: [],
    },
  ],
  meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false },
};

function monter() {
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

function erreur500(): AxiosError {
  const config = { headers: new AxiosHeaders() };
  return new AxiosError('panne', 'ERR_BAD_RESPONSE', config, null, {
    status: 500,
    statusText: 'Server Error',
    headers: {},
    config,
    data: {},
  });
}

beforeEach(() => {
  get.mockReset();
});

describe('ContactsHubPage — annulation et indépendance des chargements', () => {
  it('quitter la page ANNULE la requête de liste en vol', async () => {
    const signaux: AbortSignal[] = [];

    get.mockImplementation((url: string, config?: Config) => {
      if (config?.signal !== undefined && !url.includes('/counts')) signaux.push(config.signal);
      // Jamais résolue : c'est la requête de 100 s du constat.
      return new Promise(() => {});
    });

    const { unmount } = monter();

    await waitFor(() => {
      expect(signaux.length).toBeGreaterThan(0);
    });
    expect(signaux.every((s) => !s.aborted)).toBe(true);

    unmount();

    await waitFor(() => {
      expect(
        signaux.every((s) => s.aborted),
        'La requête de liste continue après le départ de la page : le `queryFn` ne transmet pas `signal` à axios.',
      ).toBe(true);
    });
  });

  it('des compteurs en ÉCHEC n’empêchent pas d’afficher les lignes', async () => {
    get.mockImplementation((url: string) => {
      if (url.includes('/counts')) return Promise.reject(erreur500());
      return Promise.resolve({ data: PAGE });
    });

    monter();

    expect(await screen.findByText('FICHE ACTIVE')).toBeInTheDocument();
    expect(await screen.findByText(/Compteurs indisponibles/)).toBeInTheDocument();
    // Pas de « 0 » mensonger : les vignettes sont retirées.
    expect(screen.queryByText('Dormants')).not.toBeInTheDocument();
  });

  it('les lignes s’affichent AVANT les compteurs, sans « 0 » provisoire', async () => {
    get.mockImplementation((url: string) => {
      // Compteurs lents (≈ 20 s à froid en production) : tenus en vol.
      if (url.includes('/counts')) return new Promise(() => {});
      return Promise.resolve({ data: PAGE });
    });

    monter();

    expect(await screen.findByText('FICHE ACTIVE')).toBeInTheDocument();
    expect(screen.getAllByText('…').length).toBeGreaterThan(0);
  });
});
