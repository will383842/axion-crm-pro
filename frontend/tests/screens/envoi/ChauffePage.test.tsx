/**
 * L8-F08 — écran de la chauffe sur le nouveau contrat : goulot en une phrase,
 * termes (null = « non calculé »), ressources IP et domaines, recul d'un palier
 * (confirmation, 409 en P1, doublon du jour), jamais de bouton « avancer ».
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse, type HttpHandler } from 'msw';

import { ChauffePage } from '@/features/envoi/infrastructure/ChauffePage';
import type { Chauffe, RessourceChauffe } from '@/features/envoi/infrastructure/composants/api';
import { demoDb } from '@/demo/base';
import { handlers as handlersDemo } from '@/demo/handlers/envoi/infrastructure.handlers';
import { api } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

const PALIERS: Chauffe['paliers'] = [
  { palier: 'P1', jours_min: 1, jours_max: 3, total_jour: 10 },
  { palier: 'P2', jours_min: 4, jours_max: 7, total_jour: 20 },
];

function ressource(extra: Partial<RessourceChauffe>): RessourceChauffe {
  return {
    id: 'domaine:dom-x', type: 'domaine', libelle: 'prospection-x.example', palier: 'P2', jours_effectifs: 5,
    jours_avant_palier_suivant: 3, total_palier: 20, envoyes_aujourdhui: 7, tenu_jusqu_au: null, motif_tenue: null, peut_reculer: true,
    ...extra,
  };
}

function fixture(extra: Partial<Chauffe> = {}): Chauffe {
  return {
    jour: '2027-03-10', budget_du_jour: 20, plafond_boite: 150,
    goulot: { terme: 'courbe_domaine', ressource_id: 'domaine:dom-x', libelle: 'prospection-x.example' },
    termes: { courbe_ip: null, courbe_domaine: 20, plafond_boites: 300, plafond_serveur: 400, capacite_reponse: 250 },
    ressources: [ressource({}), ressource({ id: 'ip:192.0.2.50', type: 'ip', libelle: '192.0.2.50', palier: 'P1', jours_effectifs: 2, total_palier: 10, peut_reculer: false })],
    paliers: PALIERS,
    ...extra,
  };
}

const lecture = (c: Chauffe): HttpHandler => http.get(api('/envoi/chauffe'), () => HttpResponse.json(c));

async function monter(...surcharges: HttpHandler[]) {
  const view = await renderScreen(<ChauffePage />, { path: '/envoi/chauffe', handlers: [...surcharges, ...handlersDemo] });
  await screen.findByRole('heading', { level: 1, name: 'Chauffe' });
  return view;
}

describe('ChauffePage — lecture', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('explique le goulot en une phrase', async () => {
    await monter(lecture(fixture()));
    expect(await screen.findByText(/le budget du jour est limité par/i)).toHaveTextContent(/montée du domaine prospection-x\.example/i);
  });

  it('affiche « non calculé » pour un terme null, jamais 0', async () => {
    await monter(lecture(fixture()));
    const ligne = (await screen.findByText('Montée des adresses IP')).closest('div')!;
    expect(within(ligne).getByText('non calculé')).toBeInTheDocument();
    expect(within(ligne).queryByText('0')).toBeNull();
    expect(screen.getByText(/^Montée des domaines/).closest('div')).toHaveTextContent('20');
  });

  it('range les ressources en adresses IP et domaines avec palier, jours et volume', async () => {
    await monter(lecture(fixture()));
    const carte = await screen.findByRole('region', { name: 'Chauffe de prospection-x.example' });
    expect(carte).toHaveTextContent('P2');
    expect(carte).toHaveTextContent(/dans 3 jours/);
    expect(carte).toHaveTextContent('7');
    expect(screen.getByRole('region', { name: 'Chauffe de 192.0.2.50' })).toBeInTheDocument();
  });

  it('ne montre plus rien de l’ancien affichage par boîte', async () => {
    await monter(lecture(fixture()));
    await screen.findByRole('region', { name: 'Chauffe de prospection-x.example' });
    expect(screen.queryByText(/fins? prévues?/i)).toBeNull();
    expect(screen.queryByText(/courbe/i)).toBeNull();
    expect(screen.queryByRole('button', { name: /avancer/i })).toBeNull();
  });
});

describe('ChauffePage — reculer', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('montre le bouton seulement si peut_reculer', async () => {
    await monter(lecture(fixture()));
    const a = await screen.findByRole('region', { name: 'Chauffe de prospection-x.example' });
    expect(within(a).getByRole('button', { name: /Reculer d’un palier/ })).toBeInTheDocument();
    expect(within(screen.getByRole('region', { name: 'Chauffe de 192.0.2.50' })).queryByRole('button', { name: /Reculer/ })).toBeNull();
  });

  it('demande une confirmation en clair avant de reculer, et permet d’annuler', async () => {
    const u = userEvent.setup();
    let appels = 0;
    await monter(lecture(fixture()), http.post(api('/envoi/chauffe/:id/reculer'), () => { appels += 1; return HttpResponse.json(ressource({ palier: 'P1', peut_reculer: false })); }));
    await u.click(await screen.findByRole('button', { name: /Reculer d’un palier/ }));
    const dialogue = await screen.findByRole('dialog');
    expect(dialogue).toHaveTextContent(/la ressource ralentit, le budget du jour baisse/i);
    await u.click(within(dialogue).getByRole('button', { name: 'Annuler' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(appels).toBe(0);
  });

  it('recule après confirmation et le dit', async () => {
    const u = userEvent.setup();
    await monter(lecture(fixture()), http.post(api('/envoi/chauffe/:id/reculer'), () => HttpResponse.json(ressource({ palier: 'P1', jours_effectifs: 1, total_palier: 10, peut_reculer: false }))));
    await u.click(await screen.findByRole('button', { name: /Reculer d’un palier/ }));
    await u.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Reculer' }));
    expect(await screen.findByRole('status')).toHaveTextContent(/reculé d’un palier : P2 → P1/i);
  });

  it('second recul le même jour : pas d’erreur, « déjà reculé aujourd’hui »', async () => {
    const u = userEvent.setup();
    await monter(lecture(fixture()), http.post(api('/envoi/chauffe/:id/reculer'), () => HttpResponse.json(ressource({ peut_reculer: false }))));
    await u.click(await screen.findByRole('button', { name: /Reculer d’un palier/ }));
    await u.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Reculer' }));
    expect(await screen.findByText(/déjà reculé aujourd’hui/i)).toBeInTheDocument();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('409 en P1 : message lisible', async () => {
    const u = userEvent.setup();
    await monter(
      lecture(fixture()),
      http.post(api('/envoi/chauffe/:id/reculer'), () => HttpResponse.json({ code: 'deja_au_premier_palier', message: 'Cette ressource est déjà au premier palier : il n’y a rien à reculer.' }, { status: 409 })),
    );
    await u.click(await screen.findByRole('button', { name: /Reculer d’un palier/ }));
    await u.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Reculer' }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/déjà au premier palier/i);
  });

  it('démo : reculer l’IP ne change pas le domaine à l’écran', async () => {
    const u = userEvent.setup();
    await monter();
    const ip = await screen.findByRole('region', { name: 'Chauffe de 192.0.2.11' });
    const domaine = screen.getByRole('region', { name: 'Chauffe de prospection-c.example' });
    const avant = domaine.textContent;
    await u.click(within(ip).getByRole('button', { name: /Reculer d’un palier/ }));
    await u.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Reculer' }));
    await waitFor(() => expect(within(screen.getByRole('region', { name: 'Chauffe de 192.0.2.11' })).queryByRole('button', { name: /Reculer/ })).toBeNull());
    expect(screen.getByRole('region', { name: 'Chauffe de prospection-c.example' }).textContent).toBe(avant);
  });
});

describe('ChauffePage — accessibilité', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('régions nommées, table légendée, dialogue nommé, résultat annoncé, bouton précis par ressource', async () => {
    const u = userEvent.setup();
    await monter(lecture(fixture()), http.post(api('/envoi/chauffe/:id/reculer'), () => HttpResponse.json(ressource({ palier: 'P1', peut_reculer: false }))));
    expect(await screen.findByRole('table', { name: /paliers/i })).toBeInTheDocument();
    expect(screen.getAllByRole('region').every((r) => r.getAttribute('aria-label') || r.getAttribute('aria-labelledby'))).toBe(true);
    const bouton = screen.getByRole('button', { name: 'Reculer d’un palier : prospection-x.example' });
    await u.click(bouton);
    const dialogue = await screen.findByRole('dialog');
    expect(dialogue).toHaveAccessibleName(/reculer/i);
    await u.click(within(dialogue).getByRole('button', { name: 'Reculer' }));
    expect(await screen.findByRole('status')).toBeInTheDocument();
  });
});
