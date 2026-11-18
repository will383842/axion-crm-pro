/**
 * LI-D1 — écran de la file « prêt à cliquer ». Le fondateur clique lui-même sur LinkedIn : l'écran n'ouvre
 * aucun profil tout seul, ne contacte personne, et n'envoie que des gestes d'idempotence stable.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { FileLinkedinPage } from './FileLinkedinPage';
import { renderScreen } from '../../../tests/helpers/renderScreen';
import { apiUrl, getJson, getStatus, http, HttpResponse } from '../../../tests/msw/handlers';
import type { HttpHandler } from '../../../tests/msw/handlers';

const ID = '11111111-1111-4111-8111-111111111111';
const FILE = {
  prospects: [{ id: ID, profil: 'https://www.linkedin.com/in/exemple-test/', entreprise: 'Exemple SA', score: 80, texte: '[ESSAI] Bonjour, je souhaiterais vous ajouter à mon réseau professionnel.' }],
  raison: null,
  arret: false,
  restants: { jour: 9, semaine: 40 },
};

interface Appel { cle: string | null; corps: unknown }
function gestes(statut = 200, reponse: unknown = { etat: 'invite', sans_effet: false }): { handler: HttpHandler; appels: Appel[] } {
  const appels: Appel[] = [];
  return {
    appels,
    handler: http.post(apiUrl(`/prospection-linkedin/prospects/${ID}/gestes`), async ({ request }) => {
      appels.push({ cle: request.headers.get('Idempotency-Key'), corps: await request.json() });
      return HttpResponse.json(reponse as never, { status: statut });
    }),
  };
}
const monter = (handlers: HttpHandler[]) => renderScreen(<FileLinkedinPage />, { path: '/prospection-linkedin', handlers });

describe('FileLinkedinPage', () => {
  it('404 : page « module éteint », aucune carte', async () => {
    await monter([getStatus('/prospection-linkedin/file', 404)]);
    expect(await screen.findByText(/module éteint/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /copier/i })).toBeNull();
  });

  it('carte : lien du profil sécurisé, mention ESSAI, restants, jamais de navigation automatique', async () => {
    await monter([getJson('/prospection-linkedin/file', FILE)]);
    const lien = await screen.findByRole('link', { name: /ouvrir le profil/i });
    expect(lien).toHaveAttribute('href', 'https://www.linkedin.com/in/exemple-test/');
    expect(lien).toHaveAttribute('target', '_blank');
    expect(lien).toHaveAttribute('rel', 'noopener noreferrer');
    expect(screen.getByText(/\[ESSAI\]/)).toBeInTheDocument();
    expect(screen.getByText(/9/)).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /arrêt/i })).toBeInTheDocument();
  });

  it('nouvel essai après une panne : la MÊME clé d’idempotence repart', async () => {
    const g = gestes(503, { refus: 'indisponible' });
    await monter([getJson('/prospection-linkedin/file', FILE), g.handler]);
    const bouton = await screen.findByRole('button', { name: /invitation faite/i });
    await userEvent.click(bouton);
    await waitFor(() => expect(g.appels).toHaveLength(1));
    await waitFor(() => expect(bouton).toBeEnabled());
    await userEvent.click(bouton);
    await waitFor(() => expect(g.appels).toHaveLength(2));
    expect(g.appels[0]?.cle).toMatch(/^.{16,128}$/);
    expect(g.appels[1]?.cle).toBe(g.appels[0]?.cle);
    expect(g.appels[0]?.corps).toEqual({ geste: 'invitation_faite' });
    // Un autre geste a sa propre clé.
    expect(screen.getByRole('link', { name: /ouvrir le profil/i })).toBeInTheDocument();
  });

  it('409 oppose : la carte est retirée et le bandeau d’arrêt apparaît', async () => {
    const g = gestes(409, { refus: 'oppose' });
    await monter([getJson('/prospection-linkedin/file', FILE), g.handler]);
    await userEvent.click(await screen.findByRole('button', { name: /invitation faite/i }));
    await waitFor(() => expect(screen.queryByRole('link', { name: /ouvrir le profil/i })).toBeNull());
    expect(await screen.findByRole('alert')).toHaveTextContent(/arrêt/i);
  });

  it('opposition : sans confirmation aucun appel ; confirmée, un seul appel « opposition »', async () => {
    const g = gestes(200, { etat: 'ecarte', sans_effet: false });
    await monter([getJson('/prospection-linkedin/file', FILE), g.handler]);
    await userEvent.click(await screen.findByRole('button', { name: /^opposition$/i }));
    await userEvent.click(await screen.findByRole('button', { name: /annuler/i }));
    expect(g.appels).toHaveLength(0);
    await userEvent.click(screen.getByRole('button', { name: /^opposition$/i }));
    await userEvent.click(await screen.findByRole('button', { name: /confirmer l’opposition/i }));
    await waitFor(() => expect(g.appels).toHaveLength(1));
    expect(g.appels[0]?.corps).toEqual({ geste: 'opposition' });
  });

  it('copier le texte : le texte prêt part dans le presse-papiers, aucun autre appel', async () => {
    await monter([getJson('/prospection-linkedin/file', FILE)]);
    const ecrits: string[] = [];
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText: (t: string) => { ecrits.push(t); return Promise.resolve(); } } });
    await userEvent.click(await screen.findByRole('button', { name: /copier le texte/i }));
    await waitFor(() => expect(ecrits).toEqual([FILE.prospects[0]!.texte]));
  });
});
