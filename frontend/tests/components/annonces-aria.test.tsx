/**
 * D28-014 — CE QUI N'ÉTAIT ANNONCÉ NULLE PART.
 *
 * ⚠️ L'INTITULÉ DU CONSTAT EST EN PARTIE PÉRIMÉ, et il faut le dire : la moitié
 * « aucun ErrorBoundary monté » est FERMÉE depuis P6-UI-005 (trois sites de
 * montage, garde `ErrorBoundary.montage.test.tsx`). Ce qui restait ouvert, et
 * que ces gardes couvrent, tient en trois points mesurés le 2026-08-22 :
 *
 *  1. `CompaniesTableSkeleton` portait `aria-busy="true"` et RIEN d'autre :
 *     `aria-busy` qualifie l'état d'une région, il n'en crée pas — l'attente la
 *     plus fréquente du produit passait sous silence ;
 *  2. `Spinner` portait `aria-label="Chargement"` sur un `<svg>` SANS rôle :
 *     un `svg` n'expose pas de rôle implicite, l'étiquette n'était pas lue ;
 *  3. `grep -rn aria-live src --include=*.tsx` ne rendait AUCUNE ligne : dans
 *     une application d'une seule page, changer d'écran ne recharge rien, et
 *     qui ne voit pas la page n'apprenait jamais qu'il avait changé d'endroit.
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import type { ReactNode } from 'react';

let cheminCourant = '/';

vi.mock('@tanstack/react-router', () => ({
  Outlet: () => <div data-testid="ecran-courant" />,
  Link: ({ children, to }: { children?: ReactNode; to: string }) => <a href={to}>{children}</a>,
  // Present pour les composants du systeme de design charges par `@/components/ui`
  // (recherche globale, notamment) : ils l'appellent au rendu.
  useNavigate: () => () => {},
  useRouterState: ({ select }: { select: (s: { location: { pathname: string } }) => unknown }) =>
    select({ location: { pathname: cheminCourant } }),
}));

vi.mock('@/lib/api', () => ({
  api: { get: () => Promise.resolve({ data: { user: { id: 'u1', current_workspace_id: null } } }) },
}));
vi.mock('@/lib/echo', () => ({ subscribeWorkspaceNotifications: () => () => {} }));
vi.mock('@/components/layout/Header', () => ({ Header: () => <div /> }));
vi.mock('@/components/layout/Sidebar', () => ({ Sidebar: () => <div /> }));
vi.mock('@/components/OnboardingTour', () => ({ OnboardingTour: () => null }));

const { RootLayout } = await import('@/app/RootLayout');
const { CompaniesTableSkeleton } = await import('@/components/ui/Skeleton');
const { Spinner } = await import('@/components/ui/Spinner');

describe('D28-014 — les attentes se disent', () => {
  it('le squelette de liste est une région d’annonce, pas seulement « occupé »', () => {
    render(<CompaniesTableSkeleton rows={2} />);
    const region = screen.queryByRole('status');

    expect(
      region !== null,
      'D28-014 : `CompaniesTableSkeleton` n’expose plus `role="status"`. ' +
        '`aria-busy` seul ne crée aucune région d’annonce : l’attente la plus ' +
        'fréquente du produit redevient silencieuse. GESTE : rétablir ' +
        '`role="status" aria-live="polite"` sur le conteneur dans ' +
        '`src/components/ui/Skeleton.tsx`.',
    ).toBe(true);

    expect(
      region?.getAttribute('aria-busy'),
      'D28-014 : `aria-busy="true"` a disparu du squelette. Il dit que la région ' +
        'est en cours de mise à jour ; sans lui, le lecteur d’écran peut lire un ' +
        'contenu encore incomplet. GESTE : le remettre dans ' +
        '`src/components/ui/Skeleton.tsx`.',
    ).toBe('true');
  });

  it('le rouet porte un rôle, sans quoi son étiquette n’est pas lue', () => {
    const { container } = render(<Spinner />);
    const svg = container.querySelector('svg');

    expect(
      svg?.getAttribute('role'),
      'D28-014 : le `<svg>` de `Spinner` n’a plus `role="img"`. Un `svg` sans ' +
        'rôle n’expose pas son `aria-label` : l’étiquette « Chargement » est ' +
        'écrite et jamais lue. GESTE : remettre `role="img"` dans ' +
        '`src/components/ui/Spinner.tsx`.',
    ).toBe('img');

    expect(screen.queryByRole('img', { name: /chargement/i })).not.toBeNull();
  });
});

describe('D28-014 — le changement d’écran s’annonce', () => {
  it('écrit le nom du nouvel écran dans une région polie, et rien au premier rendu', async () => {
    cheminCourant = '/';
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    const { container, rerender } = render(
      <QueryClientProvider client={client}>
        <RootLayout />
      </QueryClientProvider>,
    );

    const region = container.querySelector('[data-annonce-de-route]');
    expect(
      region !== null,
      'D28-014 : `RootLayout` n’a plus de région d’annonce de route. Dans une ' +
        'application d’une seule page, changer d’écran ne recharge rien : sans ' +
        'cette région, qui ne voit pas la page n’apprend jamais qu’il a changé ' +
        'd’endroit. GESTE : rétablir le `<div role="status" aria-live="polite">` ' +
        'de `src/app/RootLayout.tsx`.',
    ).toBe(true);

    expect(
      region?.getAttribute('aria-live'),
      'D28-014 : la région d’annonce n’est plus `polite`. En `assertive`, elle ' +
        'COUPE la lecture en cours à chaque navigation — le bavardage est le ' +
        'risque réel de ce dispositif. GESTE : repasser à `aria-live="polite"` ' +
        'dans `src/app/RootLayout.tsx`.',
    ).toBe('polite');

    expect(
      region?.textContent?.trim(),
      'D28-014 : la région annonce quelque chose dès le PREMIER rendu. L’arrivée ' +
        'sur la page est déjà annoncée par le navigateur : la redire fait ' +
        'doublon. GESTE : garder le garde-fou `premierRendu` dans ' +
        '`src/app/RootLayout.tsx`.',
    ).toBe('');

    cheminCourant = '/admin/observability';
    rerender(
      <QueryClientProvider client={client}>
        <RootLayout />
      </QueryClientProvider>,
    );

    await waitFor(() => {
      expect(
        container.querySelector('[data-annonce-de-route]')?.textContent ?? '',
        'D28-014 : après une navigation, la région d’annonce reste vide — ou ' +
          'n’annonce pas le nom de l’écran. Elle doit nommer l’écran atteint, ' +
          'avec le libellé du fil d’Ariane (`libelleDeChemin`), sinon elle dit ' +
          'un mot d’URL. GESTE : vérifier le `useEffect` sur le chemin dans ' +
          '`src/app/RootLayout.tsx`.',
      ).toContain('Observabilité');
    });
  });
});
