/**
 * P1-12 — les réglages techniques quittés des Paramètres sont bien rendus sous
 * « Technique › Santé du système », y compris quand le résumé échoue.
 */
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';

import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, http, HttpResponse } from '../msw/handlers';

describe('Santé du système — réglages techniques', () => {
  it('affiche « Réglages techniques » avec intégrations, suivi des erreurs et Horizon', async () => {
    await renderScreen(<ObservabilityPage />, {
      path: '/admin/observability',
      handlers: [http.get(apiUrl('/observability/summary'), () => new HttpResponse(null, { status: 500 }))],
    });

    expect(await screen.findByRole('heading', { name: 'Réglages techniques' })).toBeInTheDocument();
    expect(screen.getByText('INSEE_API_KEY')).toBeInTheDocument();
    expect(screen.getByText('Suivi des erreurs')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: /Horizon/ })).toBeInTheDocument();
  });
});
