/**
 * 2026-10-03 — « Chargement de la santé du système… » sans fin.
 *
 * En production, le résumé ne répondait pas en 10 s. Le client de l'application
 * réessaie trois fois par défaut (`main.tsx`, 30 s de délai chacune) : l'écran
 * restait plus d'une minute et demie sur « Chargement… » avant d'avouer
 * l'échec. La requête du résumé ne réessaie plus d'elle-même : l'échec
 * s'affiche tout de suite, avec « Réessayer ».
 *
 * Le client de ce test reprend la règle de nouvel essai de `main.tsx` : avec le
 * client de test par défaut (`retry: false`), la garde passerait même si la
 * requête réessayait.
 */
import { describe, expect, it } from 'vitest';
import { QueryClient } from '@tanstack/react-query';
import { screen } from '@testing-library/react';

import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, http, HttpResponse } from '../msw/handlers';

/** La règle de nouvel essai de production (`src/main.tsx`). */
function clientCommeEnProduction(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: {
        gcTime: 0,
        retry: (count, err) => {
          const status = (err as { response?: { status?: number } } | null)?.response?.status;
          return status !== 401 && status !== 403 && count < 2;
        },
      },
    },
  });
}

describe('Santé du système — un échec s’affiche, jamais un chargement sans fin', () => {
  it('sous 500 : l’état d’erreur apparaît sans nouvel essai, et « Chargement » disparaît', async () => {
    let appels = 0;
    await renderScreen(<ObservabilityPage />, {
      path: '/admin/observability',
      queryClient: clientCommeEnProduction(),
      handlers: [
        http.get(apiUrl('/observability/summary'), () => {
          appels += 1;
          return new HttpResponse(null, { status: 500 });
        }),
      ],
    });

    // Délai de `findBy` (1 s) inférieur au premier délai de nouvel essai de
    // React Query (1 s, puis 2 s) : si la requête réessayait, l'erreur
    // n'apparaîtrait pas à temps.
    expect(await screen.findByText('Le serveur est en panne')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
    expect(screen.queryByText(/Chargement de la santé du système/)).not.toBeInTheDocument();
    expect(appels).toBe(1);
  });
});
