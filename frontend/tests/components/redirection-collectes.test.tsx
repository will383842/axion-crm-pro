/**
 * DM0-COL : les anciennes adresses `/campaigns…` mènent aux nouvelles
 * `/collectes…`. Redirections gardées 6 mois (jusqu'au 2027-05-01).
 * Même méthode que `redirections-navigation-cible.test.tsx` : on joue le
 * `beforeLoad` de la route et on lit la redirection jetée.
 */
import { describe, expect, it } from 'vitest';
import { isRedirect } from '@tanstack/react-router';

import { routeTree } from '@/app/routeTree';

type RouteLike = {
  options?: { path?: string; beforeLoad?: (arg: unknown) => unknown };
  children?: unknown;
};

function toutesLesRoutes(noeud: unknown): RouteLike[] {
  const route = noeud as RouteLike;
  const enfants = route?.children;
  const liste: RouteLike[] = route?.options !== undefined ? [route] : [];
  const suite = Array.isArray(enfants)
    ? enfants
    : enfants !== null && typeof enfants === 'object'
      ? Object.values(enfants as Record<string, unknown>)
      : [];
  for (const e of suite) liste.push(...toutesLesRoutes(e));
  return liste;
}

const ROUTES = toutesLesRoutes(routeTree);

function redirection(chemin: string, params: Record<string, string> = {}) {
  const route = ROUTES.find((r) => r.options?.path === chemin);
  if (route === undefined) throw new Error(`Aucune route ne déclare ${chemin}.`);
  const avant = route.options?.beforeLoad;
  if (typeof avant !== 'function') throw new Error(`${chemin} ne redirige rien.`);
  try {
    avant({ params });
  } catch (jete: unknown) {
    if (isRedirect(jete)) {
      return (jete as unknown as { options: { to?: string; params?: Record<string, string> } }).options;
    }
    throw jete;
  }
  throw new Error(`${chemin} n'a rien jeté.`);
}

describe('DM0-COL — /campaigns devient /collectes', () => {
  it('les trois routes /collectes existent', () => {
    for (const p of ['/collectes', '/collectes/new', '/collectes/$collecteId']) {
      expect(ROUTES.some((r) => r.options?.path === p), p).toBe(true);
    }
  });

  it('/campaigns mène à /collectes', () => {
    expect(redirection('/campaigns').to).toBe('/collectes');
  });

  it('/campaigns/new mène à /collectes/new', () => {
    expect(redirection('/campaigns/new').to).toBe('/collectes/new');
  });

  it('/campaigns/42 mène à /collectes/42', () => {
    const r = redirection('/campaigns/$campaignId', { campaignId: '42' });
    expect(r.to).toBe('/collectes/$collecteId');
    expect(r.params).toEqual({ collecteId: '42' });
  });
});
