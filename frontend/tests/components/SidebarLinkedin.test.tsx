/**
 * UI-LI — la sonde « File LinkedIn » de la barre latérale : clé de cache par espace, une seule requête
 * pour plusieurs rendus, nouvelle requête au changement d'espace.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';

const appels: string[] = [];

vi.mock('@tanstack/react-router', () => ({
  Link: ({ children, to }: { children: React.ReactNode; to: string }) => <span data-to={to}>{children}</span>,
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) => select({ location: { pathname: '/' } }),
}));

vi.mock('@/lib/api', () => ({
  api: {
    get: (chemin: string) => {
      appels.push(chemin);
      return Promise.resolve({ data: {} });
    },
  },
}));

const { Sidebar } = await import('@/components/layout/Sidebar');
const { CONSOLE_FEATURES_KEY } = await import('@/features/crm-console/useConsoleFeatures');

const SONDE = '/prospection-linkedin/file';
const sondes = () => appels.filter((a) => a === SONDE).length;

function client(espace: string | null): QueryClient {
  const c = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  c.setQueryData(CONSOLE_FEATURES_KEY, { console_v2: true, universes: { business: true, vivier: false } });
  c.setQueryData(['auth', 'me'], { user: { id: 'u1', current_workspace_id: espace } });
  return c;
}

const barre = (c: QueryClient) => (
  <QueryClientProvider client={c}>
    <Sidebar collapsed={false} onToggleCollapse={() => undefined} />
  </QueryClientProvider>
);

describe('Sidebar — sonde LinkedIn', () => {
  beforeEach(() => {
    appels.length = 0;
  });

  it('deux rendus du même espace : une seule requête', async () => {
    const c = client('espace-a');
    const premier = render(barre(c));
    await waitFor(() => { expect(sondes()).toBe(1); });
    premier.unmount();
    render(barre(c));
    await screen.findAllByRole('navigation');
    await act(async () => { await new Promise((r) => setTimeout(r, 50)); });
    expect(sondes()).toBe(1);
  });

  it('changement d’espace : nouvelle requête, clé propre à chaque espace', async () => {
    const c = client('espace-a');
    render(barre(c));
    await waitFor(() => { expect(sondes()).toBe(1); });
    act(() => { c.setQueryData(['auth', 'me'], { user: { id: 'u1', current_workspace_id: 'espace-b' } }); });
    await waitFor(() => { expect(sondes()).toBe(2); });
    expect(c.getQueryCache().findAll({ queryKey: ['menu', 'prospection-linkedin-disponible'] }).map((q) => q.queryKey[2]).sort()).toEqual(['espace-a', 'espace-b']);
  });

  it('espace inconnu : aucune sonde (pas de réponse partagée entre espaces)', async () => {
    render(barre(client(null)));
    await screen.findAllByRole('navigation');
    await act(async () => { await new Promise((r) => setTimeout(r, 50)); });
    expect(sondes()).toBe(0);
  });
});
