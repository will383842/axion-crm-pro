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
import { beforeEach, describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MediaDetailPage } from '@/features/media/MediaDetailPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';
import { JournalistDetailPage } from '@/features/media/JournalistDetailPage';
import { EnvoisPressePage } from '@/features/media/EnvoisPressePage';
import { demoDb } from '@/demo/base';
import { server } from '../msw/server';
import { handlers as handlersDemo } from '@/demo/handlers/crm/presse-reseaux.handlers';

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

describe('DM1-PRE — presse en démo (faux serveur)', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('la fiche du Courrier du Rhône montre l’opposé aux envois et son historique « via Léa Fournier »', async () => {
    await renderScreen(<MediaDetailPage />, { path: '/media/$mediaId', url: '/media/1', handlers: handlersDemo });
    expect(await screen.findByRole('heading', { name: 'Le Courrier du Rhône' })).toBeVisible();
    expect(await screen.findByText('opposé aux envois')).toBeVisible();
    expect(screen.getAllByText(/via Léa Fournier/).length).toBeGreaterThan(0);
  });

  it('consigner une relance sur la fiche de la rédaction l’ajoute à l’historique', async () => {
    const user = userEvent.setup();
    await renderScreen(<MediaDetailPage />, { path: '/media/$mediaId', url: '/media/1', handlers: handlersDemo });
    await screen.findByRole('heading', { name: 'Le Courrier du Rhône' });
    await user.selectOptions(screen.getByRole('combobox'), 'press_followup');
    await user.type(screen.getByPlaceholderText(/En une ligne/), 'Relance du salon de Vienne');
    await user.click(screen.getByRole('button', { name: 'Consigner' }));
    expect(await screen.findByText('Relance du salon de Vienne')).toBeVisible();
  });

  it('une saisie sans titre est refusée par le faux serveur (422), rien n’est consigné', async () => {
    server.use(...handlersDemo);
    const avant = demoDb.zone<{ id: number }>('presse', 'echanges').compter();
    const reponse = await fetch('https://api.localhost/api/v1/journalists/1/activities', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ kind: 'press_followup', title: '  ' }),
    });
    expect(reponse.status).toBe(422);
    expect(demoDb.zone<{ id: number }>('presse', 'echanges').compter()).toBe(avant);
  });

  it('la fiche journaliste consigne une relance et l’affiche dans son historique', async () => {
    const user = userEvent.setup();
    await renderScreen(<JournalistDetailPage />, { path: '/journalists/$journalistId', url: '/journalists/1', handlers: handlersDemo });
    expect(await screen.findByRole('heading', { name: 'Léa Fournier' })).toBeVisible();
    await user.selectOptions(screen.getByRole('combobox'), 'press_followup');
    await user.type(screen.getByPlaceholderText(/En une ligne/), 'Relance après l’article');
    await user.click(screen.getByRole('button', { name: 'Consigner' }));
    expect(await screen.findByText('Relance après l’article')).toBeVisible();
  });

  it('la fiche d’un contact opposé affiche l’opposition', async () => {
    const oppose = demoDb.zone<{ id: number; opt_out: boolean }>('presse', 'journalistes').tous().find((j) => j.opt_out);
    expect(oppose).toBeDefined();
    await renderScreen(<JournalistDetailPage />, { path: '/journalists/$journalistId', url: `/journalists/${oppose!.id}`, handlers: handlersDemo });
    expect(await screen.findByText(/Opposition RGPD/)).toBeVisible();
  });

  it('le registre des communiqués liste les envois ; les compteurs du trimestre ne s’affichent que si le serveur les annonce', async () => {
    await renderScreen(<EnvoisPressePage />, { path: '/presse/envois', url: '/presse/envois', handlers: handlersDemo });
    expect((await screen.findAllByText('Communiqué : salon des artisans de Vienne', { selector: 'td' })).length).toBeGreaterThan(0);
    // Le faux serveur annonce les compteurs : la bande s'affiche.
    expect(screen.getByTestId('compteurs-communiques')).toBeVisible();
  });

  it('sans annonce du serveur (vrai CRM), ni compteurs ni « Envoyer un communiqué »', async () => {
    await renderScreen(<EnvoisPressePage />, {
      path: '/presse/envois',
      url: '/presse/envois',
      handlers: [getJson('/presse/envois', { data: [], meta: { total: 0, per_page: 50, current_page: 1, last_page: 1 } })],
    });
    expect(await screen.findByText('Aucun envoi consigné')).toBeVisible();
    expect(screen.queryByTestId('compteurs-communiques')).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Envoyer un communiqué' })).not.toBeInTheDocument();
  });
});
