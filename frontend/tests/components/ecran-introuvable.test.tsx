/**
 * L'ÉCRAN « PAGE INTROUVABLE », BRANCHÉ POUR DE BON — D22-007 et D28-013.
 *
 * Mesure du 2026-08-22, avant réparation : `NotFoundPage` existait, était
 * importé, était livré dans le bundle… et ne s'affichait JAMAIS. Il pendait à
 * une route `path: '/*'`, or le jeton fourre-tout de TanStack Router v1 est `$`
 * et non `*` (`router-core/dist/esm/new-process-route-tree.js:53` ne teste que
 * le code 36) : `'/*'` n'était qu'un segment statique nommé « * ». Une URL
 * inconnue tombait sur le `<p>Not Found</p>` anglais de la librairie
 * (`react-router/dist/esm/not-found.js:41`) — d'où D28-013, « aucun élément
 * atteignable au clavier » : un paragraphe n'en offre aucun.
 *
 * Cette garde MONTE l'arbre de routes RÉEL (`src/app/routeTree.tsx`) sur une URL
 * inconnue. Réécrire l'arbre ici l'aurait rendue vraie par construction : c'est
 * précisément le piège que ce dépôt a déjà payé (cf. l'en-tête de
 * `ErrorBoundary.montage.test.tsx`).
 */
import { beforeAll, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  RouterProvider,
  createMemoryHistory,
  createRouter,
  type AnyRoute,
  type AnyRouter,
} from '@tanstack/react-router';

/**
 * ⚠️ IMPORT DYNAMIQUE, comme dans `ErrorBoundary.montage.test.tsx` :
 * `routeTree` charge les ~37 modules d'écran, dont `CoveragePage` →
 * `maplibre-gl`, qui appelle `window.URL.createObjectURL` AU CHARGEMENT DU
 * MODULE. jsdom ne la fournit pas ; en import statique, le fichier entier
 * échouerait à la collecte avant le moindre cas. Lacune de jsdom, pas défaut du
 * produit.
 */
let arbre: AnyRoute;

beforeAll(async () => {
  if (typeof window.URL.createObjectURL !== 'function') {
    Object.defineProperty(window.URL, 'createObjectURL', {
      writable: true,
      configurable: true,
      value: () => 'blob:bouchon-jsdom',
    });
    Object.defineProperty(window.URL, 'revokeObjectURL', {
      writable: true,
      configurable: true,
      value: () => {},
    });
  }
  const module = await import('@/app/routeTree');
  arbre = module.routeTree as AnyRoute;
});

async function monterSurUrlInconnue(url: string) {
  const router: AnyRouter = createRouter({
    routeTree: arbre,
    history: createMemoryHistory({ initialEntries: [url] }),
    defaultPreload: 'intent',
  });
  await router.load();

  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
}

describe('D22-007 — une URL inconnue affiche l’écran du produit', () => {
  it('rend « Page introuvable » et non le Not Found anglais de la librairie', async () => {
    await monterSurUrlInconnue('/cette-url-n-existe-pas');

    await waitFor(() => {
      expect(
        screen.queryByText(/page introuvable/i) !== null,
        'D22-007 : une URL inconnue n’affiche plus `NotFoundPage`. L’utilisateur ' +
          'tombe alors sur le `<p>Not Found</p>` anglais de TanStack Router. ' +
          'GESTE : rétablir `notFoundComponent: NotFoundPage` sur `rootRoute` ' +
          'dans `src/app/routeTree.tsx` (et ne PAS le remplacer par une route ' +
          '`path: "/*"`, qui n’attrape rien en v1).',
      ).toBe(true);
    });

    expect(
      screen.queryByText(/^not found$/i) === null,
      'D22-007 : l’écran par défaut anglais de la librairie s’affiche encore. ' +
        'GESTE : vérifier `notFoundComponent` sur `rootRoute` ' +
        '(`src/app/routeTree.tsx`).',
    ).toBe(true);
  });
});

describe('D28-013 — le 404 offre une sortie au clavier', () => {
  it('porte un lien focalisable vers le tableau de bord', async () => {
    await monterSurUrlInconnue('/cette-url-n-existe-pas-non-plus');

    const lien = await screen.findByRole('link', { name: /retour au tableau de bord/i });
    lien.focus();
    expect(
      document.activeElement === lien,
      'D28-013 : le lien de sortie du 404 n’est pas atteignable au clavier. Un ' +
        'écran d’erreur sans aucun élément focalisable enferme qui navigue sans ' +
        'souris. GESTE : garder un `<Link to="/">` dans ' +
        '`src/features/misc/NotFoundPage.tsx` et ne poser ni `tabindex="-1"` ni ' +
        '`pointer-events-none` dessus.',
    ).toBe(true);
  });

  it('rend son contenu dans un repère de région <main> (D28-012)', async () => {
    const { container } = await monterSurUrlInconnue('/encore-une-url-inconnue');
    await screen.findByText(/page introuvable/i);

    expect(
      container.querySelector('main') !== null,
      'D28-012 : le 404 ne porte plus de `<main>`. Rendu par la racine, il vit ' +
        'HORS de la coquille : sans cette balise, la page n’offre aucun repère de ' +
        'région à une navigation par points de repère. GESTE : rétablir le ' +
        '`<main>` de `src/features/misc/NotFoundPage.tsx`.',
    ).toBe(true);
  });
});
