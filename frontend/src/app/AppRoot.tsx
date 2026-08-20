/**
 * `AppRoot` — l'arbre applicatif, extrait de `src/main.tsx`.
 *
 * POURQUOI CE FICHIER EXISTE (P6-UI-005) : `main.tsx` melange deux choses,
 * l'AMORCAGE (lire `#root`, `createRoot`, `initSentry`, choisir StrictMode) et
 * la COMPOSITION (quels fournisseurs enveloppent quoi). L'amorcage n'est pas
 * testable — `document.getElementById('root')` est nul sous vitest et le module
 * leve a l'import. Tant que la composition vivait dedans, la seule facon
 * d'affirmer « la frontiere d'erreur est bien montee a la racine » aurait ete
 * de relire le source au grep, c'est-a-dire de croire un texte plutot que de
 * mesurer un comportement.
 *
 * Separer les deux rend la composition MONTABLE dans un test
 * (`tests/components/ErrorBoundary.montage.test.tsx`) : on lui passe un routeur
 * dont un ecran explose, et on verifie ce que l'utilisateur voit. Si quelqu'un
 * retire la frontiere ci-dessous, la garde rougit — ce qui n'etait pas
 * possible avant.
 */
import type { ReactElement } from 'react';
import { QueryClientProvider, type QueryClient } from '@tanstack/react-query';
import { RouterProvider, type AnyRouter } from '@tanstack/react-router';
import { Toaster } from 'sonner';
import { ErrorBoundary } from '@/components/ui';

export interface AppRootProps {
  /**
   * Typé `AnyRouter` et non `typeof router` : le routeur reel est declare dans
   * `main.tsx` (avec son `declare module` d'enregistrement), et le test en
   * construit un autre, aux types litteraux differents. Un type exact ici
   * n'apporterait aucune securite reelle et interdirait le test.
   */
  router: AnyRouter;
  queryClient: QueryClient;
}

export function AppRoot({ router, queryClient }: AppRootProps): ReactElement {
  return (
    // P6-UI-005 — SITE DE MONTAGE 3/3, le filet de DERNIER recours.
    //
    // Il ne fait pas doublon avec les deux autres : le filet global de TanStack
    // Router est pose A L'INTERIEUR de `RouterProvider`
    // (`@tanstack/react-router/dist/esm/Matches.js:36`), et nos deux frontieres
    // de route sont plus profondes encore. AUCUN des trois ne voit une
    // exception levee par `QueryClientProvider`, par `Toaster`, ou par les
    // entrailles de `RouterProvider` : ces cas-la remontaient jusqu'a la racine
    // React, et LA, c'est bien une page blanche (`createRoot().render()` leve,
    // le document reste vide).
    //
    // Pas de `resetKey` ici : a ce niveau il n'y a plus de navigation, le seul
    // geste possible est celui que propose le niveau `root` — recharger.
    <ErrorBoundary level="root">
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
        <Toaster richColors closeButton position="top-right" />
      </QueryClientProvider>
    </ErrorBoundary>
  );
}
