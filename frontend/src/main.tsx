import { StrictMode, Fragment } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient } from '@tanstack/react-query';
import { createRouter } from '@tanstack/react-router';
import { routeTree } from './app/routeTree';
// P6-UI-005 — la COMPOSITION de l'arbre (fournisseurs + frontiere d'erreur de
// dernier recours) vit desormais dans `AppRoot`, pour qu'elle soit MONTABLE
// dans un test. Ce fichier ne garde que l'AMORCAGE, qui ne l'est pas :
// `document.getElementById('root')` est nul sous vitest.
import { AppRoot } from './app/AppRoot';
import { initSentry } from './lib/sentry';
// D26-003 — la densite d'affichage etait un `useState` local a /settings, sans
// effet et sans persistance. Elle est relue ici, a l'amorcage, comme le theme :
// un reglage d'apparence qui n'existe que dans l'ecran qui le regle n'en est
// pas un.
import { appliquerDensite, lireDensite } from './lib/densite';
import './styles/index.css';
import './lib/i18n';

// Sprint 18.8 — Sentry init (compatible GlitchTip self-hosted, no-op si pas de DSN)
initSentry();

appliquerDensite(lireDensite());

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      gcTime: 5 * 60_000,
      retry: (count, err) => {
        const status = (err as { response?: { status?: number } } | null)?.response?.status;
        return status !== 401 && status !== 403 && count < 2;
      },
      refetchOnWindowFocus: false,
    },
  },
});

const router = createRouter({
  routeTree,
  defaultPreload: 'intent',
  context: { queryClient },
});

declare module '@tanstack/react-router' {
  interface Register {
    router: typeof router;
  }
}

const rootEl = document.getElementById('root');
if (!rootEl) throw new Error('Missing #root element');

// Sprint 18.9c — StrictMode désactivé pour MapLibre (double-mount fait AbortError sur fetch geojson).
// À réactiver Sprint 19 quand on aura ajouté une vraie protection abort dans FranceCoverageMap.
const strictModeEnv = import.meta.env['VITE_STRICT_MODE'];
const Wrapper = strictModeEnv === 'true' ? StrictMode : Fragment;
// (directives `eslint-disable no-console` retirées : la règle n'est pas activée,
// ESLint les signalait comme inutilisées — commentaires uniquement, aucun effet
// sur le code exécuté.)
console.log('[Boot] VITE_STRICT_MODE=', strictModeEnv, '→ wrapper=', Wrapper === StrictMode ? 'StrictMode' : 'Fragment');
console.log('[Boot] MODE=', import.meta.env.MODE, 'PROD=', import.meta.env.PROD, 'DEV=', import.meta.env.DEV);

createRoot(rootEl).render(
  <Wrapper>
    <AppRoot router={router} queryClient={queryClient} />
  </Wrapper>,
);
