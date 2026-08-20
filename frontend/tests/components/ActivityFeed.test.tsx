import { describe, expect, it, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

/**
 * GARDE DE L'ÉCRAN D'ACCUEIL — audit 360, A-015 / D24-001 (S0).
 *
 * Le composant lisait `log.action`, `log.actor_name`, `log.resource_type`. Aucun
 * de ces champs n'est renvoyé par `GET /api/v1/audit-logs` : le contrôleur rend
 * les attributs bruts du modèle, et la table `audit_logs` porte `event_type`,
 * `path`, `status_code`. `humanizeAction(undefined)` appelait `.replace()` sur
 * `undefined`, l'exception remontait, et — aucun `errorComponent` n'étant posé —
 * **l'écran d'accueil s'effaçait entièrement, barre latérale comprise, dès
 * qu'`audit_logs` contenait UNE SEULE ligne.** Or la connexion elle-même en écrit
 * une. 64 lignes étaient déjà en production le 2026-08-19.
 *
 * Ce test rejoue la charge utile RÉELLE de l'API. Sans le correctif, il rougit.
 */

const mockGet = vi.fn();
vi.mock('@/lib/api', () => ({
  api: {
    get: (...args: unknown[]): unknown => mockGet(...args),
  },
}));

function enveloppe({ children }: { children: ReactNode }) {
  const client = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });

  return <QueryClientProvider client={client}>{children}</QueryClientProvider>;
}

/** La forme EXACTE d'une ligne rendue par GET /api/v1/audit-logs. */
const LIGNE_REELLE_DE_L_API = {
  id: '0193f0c2-1111-7000-8000-000000000001',
  workspace_id: '0193f0c2-2222-7000-8000-000000000002',
  user_id: '0193f0c2-3333-7000-8000-000000000003',
  event_type: 'auth.login',
  path: '/api/v1/auth/login',
  status_code: 200,
  ip: '203.0.113.7',
  user_agent: 'Mozilla/5.0',
  payload_hash: 'a'.repeat(64),
  prev_hash: 'b'.repeat(64),
  current_hash: 'c'.repeat(64),
  created_at: new Date().toISOString(),
};

describe('ActivityFeed', () => {
  beforeEach(() => {
    mockGet.mockReset();
  });

  it("ne s'effondre pas sur la charge utile réelle de l'API, et affiche l'évènement", async () => {
    mockGet.mockResolvedValue({ data: { data: [LIGNE_REELLE_DE_L_API] } });

    const { ActivityFeed } = await import('@/features/dashboard/components/ActivityFeed');
    render(<ActivityFeed />, { wrapper: enveloppe });

    // Le `event_type` est rendu lisible, pas ignoré.
    expect(await screen.findByText(/Auth Login/i)).toBeTruthy();
  });

  it("survit à une ligne dont TOUS les champs optionnels manquent", async () => {
    // Le pire cas : une source qui ne renvoie que l'identifiant et la date.
    mockGet.mockResolvedValue({
      data: { data: [{ id: 'x-1', created_at: new Date().toISOString() }] },
    });

    const { ActivityFeed } = await import('@/features/dashboard/components/ActivityFeed');

    // La seule assertion qui compte : le rendu ne jette pas. Un champ absent ne
    // doit jamais pouvoir effacer l'application.
    expect(() => render(<ActivityFeed />, { wrapper: enveloppe })).not.toThrow();
    expect(await screen.findByText(/Evenement/i)).toBeTruthy();
  });
});
