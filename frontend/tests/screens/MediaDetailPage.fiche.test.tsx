/**
 * ÉCRAN `/media/$mediaId` — le lien vers la FICHE HARMONISÉE (2026-09-30).
 *
 * Depuis `crm:presse:harmoniser`, chaque média est porté par une fiche CRM
 * (nature « média », relation « presse et médias ») et ses journalistes sont
 * devenus des contacts de cette fiche. La page « Médias & Presse » continue de
 * marcher telle quelle, et dit où vit désormais la fiche. Un média pas encore
 * harmonisé ne montre aucun lien (jamais un lien vers une fiche inventée).
 *
 * Fixtures FICTIVES uniquement (dépôt public).
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MediaDetailPage } from '@/features/media/MediaDetailPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const MEDIA = {
  id: 42,
  name: 'ZZ Gazette fictive',
  media_type: 'presse_quotidien',
  media_family: 'editorial',
  periodicity: null,
  editorial_theme: null,
  diffusion_zone: 'régional',
  department_code: '69',
  region_code: '84',
  city: null,
  postcode: null,
  publisher: null,
  website: null,
  email: null,
  email_confidence: null,
  phone: null,
  cppap_number: null,
  arcom_id: null,
  enrich_status: 'pending',
  siren: null,
  source: 'cppap',
  socials: null,
  journalists: [],
  children: [],
  parent: null,
};

describe('MediaDetailPage — fiche harmonisée', () => {
  it('un média harmonisé montre le lien vers sa fiche CRM, et le lien y mène', async () => {
    const user = userEvent.setup();
    const view = await renderScreen(<MediaDetailPage />, {
      path: '/media/$mediaId',
      url: '/media/42',
      handlers: [getJson('/media/42', {
        data: { ...MEDIA, company_id: 7, company: { id: 7, denomination: 'ZZ GAZETTE FICTIVE SA' } },
        timeline: [],
      })],
      landingRoutes: ['/companies/$companyId', '/media'],
    });

    const lien = await screen.findByRole('link', { name: 'ZZ GAZETTE FICTIVE SA' });
    expect(screen.getByText(/Fiche CRM/)).toBeVisible();
    await user.click(lien);
    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/companies/7');
    });
  });

  it('un média pas encore harmonisé ne montre AUCUN lien de fiche', async () => {
    await renderScreen(<MediaDetailPage />, {
      path: '/media/$mediaId',
      url: '/media/42',
      handlers: [getJson('/media/42', { data: { ...MEDIA, company_id: null, company: null }, timeline: [] })],
      landingRoutes: ['/companies/$companyId', '/media'],
    });

    expect(await screen.findByRole('heading', { name: 'ZZ Gazette fictive' })).toBeVisible();
    expect(screen.queryByText(/Fiche CRM/)).not.toBeInTheDocument();
  });
});
