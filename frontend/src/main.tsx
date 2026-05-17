import { StrictMode, Fragment } from 'react';
import { createRoot } from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { RouterProvider, createRouter } from '@tanstack/react-router';
import { Toaster } from 'sonner';
import { routeTree } from './app/routeTree';
import { initSentry } from './lib/sentry';
import './styles/index.css';
import './lib/i18n';

// Sprint 18.8 — Sentry init (compatible GlitchTip self-hosted, no-op si pas de DSN)
initSentry();

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
const Wrapper = import.meta.env['VITE_STRICT_MODE'] === 'true' ? StrictMode : Fragment;

createRoot(rootEl).render(
  <Wrapper>
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
      <Toaster richColors closeButton position="top-right" />
    </QueryClientProvider>
  </Wrapper>,
);
