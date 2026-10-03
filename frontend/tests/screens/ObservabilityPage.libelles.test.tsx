/**
 * « Santé du système » en clair (constaté en production le 03/10/2026) :
 * plus de « COMPANIES ARCHIVÉES », de « ENTREPRISE_RADIEE », de
 * « audience.refreshed » ni de « company #5507510 ».
 */
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';

import { ObservabilityPage } from '@/features/observability/ObservabilityPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const RESUME = {
  waterfall_errors_24h: 0,
  hunter_quota_month: { used: 10, soft_limit: 1000, percent: 1 },
  google_places_quota: { used: 100, soft_limit: 11500, percent: 0.9, pending_companies: 0 },
  archive_reasons: { entreprise_radiee: 12, no_email: 3, motif_futur: 1 },
  audience_failures_7d: 2,
  recent_events: [
    { id: 1, action: 'audience.refreshed', resource_type: 'audience', resource_id: '2', context: null, created_at: '2026-10-03T08:00:00Z' },
    { id: 2, action: 'company.tags_synced', resource_type: 'company', resource_id: '5507510', context: null, created_at: '2026-10-03T07:00:00Z' },
    { id: 3, action: 'code.inconnu', resource_type: null, resource_id: null, context: null, created_at: '2026-10-03T06:00:00Z' },
  ],
};

describe('Santé du système — libellés en clair', () => {
  it('affiche les titres, motifs, actions et éléments en français', async () => {
    await renderScreen(<ObservabilityPage />, {
      path: '/admin/observability',
      handlers: [getJson('/observability/summary', { data: RESUME })],
      landingRoutes: ['/companies/$companyId', '/audiences/$audienceId'],
    });

    expect(await screen.findByText('Entreprises archivées')).toBeInTheDocument();
    expect(screen.getByText('Échecs de mise à jour d’audience (7 j)')).toBeInTheDocument();
    expect(screen.getByText(/fiches déjà complètes ignorées/)).toBeInTheDocument();

    expect(screen.getByText('Entreprise radiée')).toBeInTheDocument();
    expect(screen.getByText('Sans e-mail')).toBeInTheDocument();
    expect(screen.getByText('Autre raison')).toBeInTheDocument();

    expect(screen.getByText('Audience mise à jour')).toBeInTheDocument();
    expect(screen.getByText('Étiquettes synchronisées')).toBeInTheDocument();
    expect(screen.getByText('Autre opération')).toBeInTheDocument();
    expect(screen.getByRole('columnheader', { name: 'Élément' })).toBeInTheDocument();

    expect(screen.getByRole('link', { name: 'Entreprise n° 5507510' })).toHaveAttribute('href', '/companies/5507510');
    expect(screen.getByRole('link', { name: 'Audience n° 2' })).toHaveAttribute('href', '/audiences/2');

    const texte = document.body.textContent ?? '';
    for (const brut of ['Companies', 'entreprise_radiee', 'no_email', 'audience.refreshed', 'company #', 'smart skip', 'Resource']) {
      expect(texte).not.toContain(brut);
    }
  });
});
