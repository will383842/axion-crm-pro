/**
 * DM1-TEC — la carte « Sauvegardes » n'existe que si le serveur l'annonce.
 *
 * Le CRM réel est déployé à chaque fusion : tant que le résumé de santé ne
 * porte pas `sauvegardes`, la page reste celle d'avant.
 */
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';

import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, http, HttpResponse } from '../msw/handlers';

const RESUME = {
  waterfall_errors_24h: 0,
  hunter_quota_month: { used: 1, soft_limit: 500, percent: 0 },
  google_places_quota: { used: 1, soft_limit: 12000, percent: 0, pending_companies: 0 },
  archive_reasons: {},
  audience_failures_7d: 0,
  recent_events: [],
};

describe('Santé du système — carte Sauvegardes', () => {
  it('sans annonce du serveur, aucune carte Sauvegardes', async () => {
    await renderScreen(<ObservabilityPage />, {
      path: '/admin/observability',
      handlers: [http.get(apiUrl('/observability/summary'), () => HttpResponse.json({ data: RESUME }))],
    });
    expect(await screen.findByRole('heading', { name: 'Santé du système' })).toBeInTheDocument();
    expect(screen.queryByText('Sauvegardes')).not.toBeInTheDocument();
  });

  it('quand le serveur annonce les sauvegardes, la carte montre les trois dates', async () => {
    await renderScreen(<ObservabilityPage />, {
      path: '/admin/observability',
      handlers: [
        http.get(apiUrl('/observability/summary'), () =>
          HttpResponse.json({
            data: {
              ...RESUME,
              sauvegardes: {
                derniere_copie_le: '2027-01-12T02:10:00.000Z',
                copie_hors_site_le: '2027-01-12T03:30:00.000Z',
                dernier_exercice_restauration_le: null,
                dernier_exercice_resultat: null,
              },
            },
          }),
        ),
      ],
    });
    expect(await screen.findByText('Sauvegardes')).toBeInTheDocument();
    expect(screen.getByText('Dernière copie')).toBeInTheDocument();
    expect(screen.getByText('Copie hors site')).toBeInTheDocument();
    expect(screen.getByText('Jamais')).toBeInTheDocument();
  });
});
