/**
 * DM0-COQ — « TOUT ARRÊTER », LE BOUTON DE SÉCURITÉ D'ENVOI.
 *
 * Ce que la garde tient :
 *  1. deux clics, dont une confirmation : un seul clic n'arrête rien ;
 *  2. après confirmation, `POST /envoi/tout-arreter` part et un bandeau rouge
 *     PERSISTANT dit « Envois arrêtés » ;
 *  3. « Reprendre les envois » (`POST /envoi/reprendre`) retire le bandeau ;
 *  4. sans Envoi, le bouton n'existe pas (le CRM de production ne change pas) ;
 *  5. le feu des envois (`GET /envoi/etat`) est dans l'en-tête d'Envoi ;
 *  6. maquette `campagnes.html` : le bouton de l'en-tête est en CONTOUR rouge
 *     (`btn-danger`), le rouge PLEIN est réservé à la confirmation, dont les
 *     textes sont « Tout arrêter · <espace> ? », « Ne rien changer » et
 *     « Arrêter tous les envois ».
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TOUT_ARRETER_AUSSI_DANS_LE_CRM } from '@/features/socle/ToutArreter';
import { renderScreen } from '../helpers/renderScreen';
import { server } from '../msw/server';
import { FEATURES_LES_DEUX_PRODUITS, ME_FIXTURE, apiUrl, getJson, handlersEnvoi, http, HttpResponse } from '../msw/handlers';

beforeEach(() => {
  window.sessionStorage.clear();
});

async function monterEnvoi() {
  return renderScreen(<p>Contenu</p>, {
    path: '/envoi/campagnes',
    withLayout: true,
    handlers: [
      getJson('/auth/me', { ...ME_FIXTURE, workspace: { id: 'ws-1', name: 'Axion-IA' } }),
      getJson('/config/features', FEATURES_LES_DEUX_PRODUITS),
      ...handlersEnvoi(),
    ],
  });
}

/** Classes d’un bouton rouge PLEIN (variant `destructive`), hors survol. */
const ROUGE_PLEIN = /(?:^|\s)(?:bg-rose-\d|from-rose-|bg-gradient)/;

describe('« Tout arrêter » dans l’espace Envoi', () => {
  it('le feu des envois est affiché dans l’en-tête', async () => {
    await monterEnvoi();
    expect(await screen.findByRole('link', { name: /^état des envois : normaux$/i })).toHaveAttribute('href', '/envoi/sante');
  });

  it('2 clics + confirmation → bandeau rouge ; « Reprendre les envois » le retire', async () => {
    const posts: string[] = [];
    await monterEnvoi();
    // Espion sur les POST, empilé au-dessus des handlers d'Envoi.
    server.events.on('request:start', ({ request }) => {
      if (request.method === 'POST' && request.url.startsWith(apiUrl('/envoi/'))) posts.push(new URL(request.url).pathname);
    });

    const bouton = await screen.findByRole('button', { name: /^tout arrêter$/i });
    expect(screen.queryByRole('alert')).toBeNull();

    // Premier clic : seulement la confirmation, rien n'est encore arrêté.
    await userEvent.click(bouton);
    const dialogue = await screen.findByRole('dialog', { name: /tout arrêter/i });
    expect(posts).toEqual([]);
    expect(within(dialogue).getByText(/plus aucun message ne part/i)).toBeInTheDocument();

    // Second clic : la confirmation.
    await userEvent.click(within(dialogue).getByRole('button', { name: /^arrêter tous les envois$/i }));
    const bandeau = await screen.findByRole('alert');
    expect(bandeau.textContent).toMatch(/envois arrêtés/i);
    expect(posts).toContain('/api/v1/envoi/tout-arreter');
    expect(await screen.findByRole('link', { name: /^état des envois : arrêtés$/i })).toBeInTheDocument();

    await userEvent.click(within(bandeau).getByRole('button', { name: /reprendre les envois/i }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
    expect(posts).toContain('/api/v1/envoi/reprendre');
    server.events.removeAllListeners('request:start');
  });

  it('le bouton de l’en-tête est en contour rouge, pas plein (un seul bouton plein par écran)', async () => {
    await monterEnvoi();
    const bouton = await screen.findByRole('button', { name: /^tout arrêter$/i });
    expect(bouton).toHaveAttribute('data-variante', 'contour-danger');
    expect(bouton.className).toMatch(/\bring-1\b/);
    expect(bouton.className).toMatch(/\bring-rose-\d/);
    expect(bouton.className).toMatch(/\bbg-transparent\b/);
    expect(bouton.className).not.toMatch(ROUGE_PLEIN);
  });

  it('la confirmation reprend les textes de la maquette, le rouge plein n’y est que pour confirmer', async () => {
    await monterEnvoi();
    await userEvent.click(await screen.findByRole('button', { name: /^tout arrêter$/i }));
    const dialogue = await screen.findByRole('dialog', { name: 'Tout arrêter · Axion-IA ?' });
    expect(within(dialogue).getByRole('heading', { name: 'Tout arrêter · Axion-IA ?' })).toBeInTheDocument();
    const garder = within(dialogue).getByRole('button', { name: /^ne rien changer$/i });
    const confirmer = within(dialogue).getByRole('button', { name: /^arrêter tous les envois$/i });
    expect(confirmer.className).toMatch(ROUGE_PLEIN);
    expect(garder.className).not.toMatch(ROUGE_PLEIN);
    expect(within(dialogue).queryByRole('button', { name: /annuler|oui, tout arrêter/i })).toBeNull();
  });

  it('« Ne rien changer » dans la confirmation n’arrête rien', async () => {
    await monterEnvoi();
    await userEvent.click(await screen.findByRole('button', { name: /^tout arrêter$/i }));
    const dialogue = await screen.findByRole('dialog', { name: /tout arrêter/i });
    await userEvent.click(within(dialogue).getByRole('button', { name: /^ne rien changer$/i }));
    await waitFor(() => expect(screen.queryByRole('dialog', { name: /tout arrêter/i })).toBeNull());
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('un état déjà « arrêté » au chargement affiche le bandeau (persistant)', async () => {
    await renderScreen(<p>Contenu</p>, {
      path: '/envoi/campagnes',
      withLayout: true,
      handlers: [
        // En tête de liste : avec MSW, le premier handler correspondant gagne.
        http.get(apiUrl('/envoi/etat'), () =>
          HttpResponse.json({ etat: 'arrete', feu: 'rouge', depuis: '2027-01-12T10:42:00+01:00', par: 'Will Test', motif: 'Tout arrêter' }),
        ),
        getJson('/config/features', FEATURES_LES_DEUX_PRODUITS),
        ...handlersEnvoi(),
      ],
    });
    const bandeau = await screen.findByRole('alert');
    expect(bandeau.textContent).toMatch(/envois arrêtés/i);
    expect(bandeau.textContent).toMatch(/Will Test/);
  });
});

describe('Sans Envoi — pas de « Tout arrêter »', () => {
  it('le bouton est absent du CRM (défaut du serveur)', async () => {
    const vue = await renderScreen(<p>Contenu</p>, { path: '/companies', withLayout: true });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    await screen.findAllByText('Will Test');
    expect(screen.queryByRole('button', { name: /tout arrêter/i })).toBeNull();
    expect(screen.queryByRole('link', { name: /état des envois/i })).toBeNull();
  });

  it('dans le CRM avec Envoi actif, absent aussi tant que Will ne l’a pas demandé', async () => {
    expect(TOUT_ARRETER_AUSSI_DANS_LE_CRM).toBe(false);
    const vue = await renderScreen(<p>Contenu</p>, {
      path: '/companies',
      withLayout: true,
      handlers: [getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlersEnvoi()],
    });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    expect(screen.queryByRole('button', { name: /tout arrêter/i })).toBeNull();
  });
});
