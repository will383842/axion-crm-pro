/**
 * D3 — un échec de `/auth/me` n'est PAS une permission manquante.
 *
 * Avant : la requête en erreur laissait `permissions` vide, donc l'écran affichait « Il vous manque la permission… »
 * à quelqu'un qui l'avait. Désormais le hook expose `erreur` et l'écran dit que les droits n'ont pas pu être vérifiés.
 */
import { describe, it, expect, vi } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

const { lireMe } = vi.hoisted(() => ({ lireMe: vi.fn<() => Promise<{ data: unknown }>>() }));
vi.mock('@/lib/api', () => ({ api: { get: lireMe } }));

import { useDroitsBoites, messageDroits } from '@/features/envoi/infrastructure/composants/permissions';

function enveloppe() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return ({ children }: { children: ReactNode }) => <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

describe('droits des boîtes', () => {
  it('échec de /auth/me : erreur vraie, aucun droit accordé, message de connexion (pas de permission manquante)', async () => {
    lireMe.mockRejectedValueOnce(new Error('réseau'));
    const { result } = renderHook(() => useDroitsBoites(), { wrapper: enveloppe() });
    await waitFor(() => expect(result.current.erreur).toBe(true));
    expect(result.current.suspendre).toBe(false);
    expect(result.current.reactiver).toBe(false);
    const texte = messageDroits(result.current, 'users.manage');
    expect(texte).toMatch(/n’ont pas pu être vérifiés/);
    expect(texte).not.toMatch(/Il vous manque/);
  });

  it('droits chargés mais sans la permission : message de permission manquante', async () => {
    lireMe.mockResolvedValueOnce({ data: { roles: ['viewer'], permissions: [] } });
    const { result } = renderHook(() => useDroitsBoites(), { wrapper: enveloppe() });
    await waitFor(() => expect(result.current.charges).toBe(true));
    expect(result.current.erreur).toBe(false);
    expect(messageDroits(result.current, 'users.manage')).toBe('Il vous manque la permission users.manage.');
  });
});
