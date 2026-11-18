/**
 * RR-6D — « Réponses traitées » : corriger et annuler un classement.
 *
 * Parcours complet : vide, chargé, erreur, refus (STOP définitif, 409), correction, annulation.
 * Données FICTIVES (jeu de démo). Rien n'est supprimé : « Annuler » ajoute une ligne côté serveur.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse, type HttpHandler } from 'msw';

import { ReponsesTraiteesPage } from '@/features/envoi/reponses/ReponsesTraiteesPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/reponses.handlers';
import { api, erreur } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

async function monter(supplementaires: HttpHandler[] = []) {
  return renderScreen(<ReponsesTraiteesPage />, {
    path: '/envoi/reponses/traitees',
    url: '/envoi/reponses/traitees',
    handlers: [...supplementaires, ...handlers],
  });
}

type Reponse = ReturnType<typeof demoDb.reponses.tous>[number];

/** Vide « Traitées » puis y met les réponses fictives voulues (identifiants propres au test). */
function semer(...classements: Array<['meyer' | 'perrin', 'refus' | 'stop']>): void {
  for (const r of demoDb.reponses.tous()) demoDb.reponses.modifier(r.id, { traitee: false });
  const modele = demoDb.reponses.tous()[0] as Reponse;
  const personnes = { meyer: ['Sonia', 'Meyer'], perrin: ['Hugo', 'Perrin'] } as const;
  for (const [cle, classement] of classements) {
    const [prenom, nom] = personnes[cle];
    demoDb.reponses.ajouter({
      ...modele,
      id: `rep-test-${cle}`,
      prenom,
      nom,
      de: `${cle}@exemple-rr6d.example`,
      organisation: 'Organisation fictive',
      extrait: 'Merci, pas pour le moment.',
      classement,
      suggestion: { classement, confiance: 80 },
      traitee: true,
    });
  }
}

function ligneDe(nom: string): HTMLElement {
  return screen.getByRole('listitem', { name: new RegExp(nom) });
}

describe('ReponsesTraiteesPage — corriger et annuler', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('état vide : « Aucune réponse traitée », sans bouton', async () => {
    semer();
    await monter();
    expect(await screen.findByText('Aucune réponse traitée')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Corriger|Annuler/ })).not.toBeInTheDocument();
  });

  it('état d’erreur : message en français et bouton pour réessayer', async () => {
    await monter([http.get(api('/envoi/reponses'), () => erreur(500, 'panne', 'panne'))]);
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });

  it('chargé : chaque ligne a « Corriger » et « Annuler », nommés par la personne ; aucun bouton « supprimer »', async () => {
    semer(['meyer', 'refus']);
    await monter();
    await screen.findByRole('button', { name: 'Corriger le classement de Sonia Meyer' });
    const ligne = ligneDe('Sonia Meyer');
    expect(within(ligne).getByRole('button', { name: 'Annuler le classement de Sonia Meyer' })).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /supprimer/i })).not.toBeInTheDocument();
  });

  it('corriger « Pas intéressé » en « Intéressé » appelle classer, la ligne reste dans « Traitées », le focus revient', async () => {
    const user = userEvent.setup();
    const corps: unknown[] = [];
    const espion = http.post(api('/envoi/reponses/:id/classer'), async ({ request }) => {
      corps.push(await request.clone().json());
      return undefined;
    });
    semer(['meyer', 'refus']);
    await monter([espion]);

    const corriger = await screen.findByRole('button', { name: 'Corriger le classement de Sonia Meyer' });
    await user.click(corriger);
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: /Corriger le classement de Sonia Meyer/ })).toBeInTheDocument();
    // Le classement actuel n'est pas proposé.
    expect(within(dialogue).queryByRole('button', { name: 'Pas intéressé' })).not.toBeInTheDocument();
    await user.click(within(dialogue).getByRole('button', { name: 'Intéressé' }));

    await waitFor(() => expect(corps).toHaveLength(1));
    expect(corps[0]).toMatchObject({ classement: 'interesse' });
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(within(ligneDe('Sonia Meyer')).getByText('Intéressé')).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Corriger le classement de Sonia Meyer' })).toHaveFocus());
  });

  it('corriger en « STOP » passe par la confirmation du STOP', async () => {
    const user = userEvent.setup();
    const corps: unknown[] = [];
    const espion = http.post(api('/envoi/reponses/:id/classer'), async ({ request }) => {
      corps.push(await request.clone().json());
      return undefined;
    });
    semer(['meyer', 'refus']);
    await monter([espion]);

    await user.click(await screen.findByRole('button', { name: 'Corriger le classement de Sonia Meyer' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'STOP' }));
    // Rien n'est parti : la confirmation du STOP s'affiche d'abord.
    expect(corps).toHaveLength(0);
    const stop = await screen.findByRole('dialog', { name: /à l’écart des envois/ });
    await user.click(within(stop).getByRole('button', { name: 'Confirmer le STOP' }));
    await waitFor(() => expect(corps).toHaveLength(1));
    expect(corps[0]).toMatchObject({ classement: 'stop' });
  });

  it('annuler : confirmation en français, puis la réponse quitte « Traitées » et « À traiter » remonte de 1', async () => {
    const user = userEvent.setup();
    const appels: string[] = [];
    const espion = http.post(api('/envoi/reponses/:id/annuler'), ({ params }) => {
      appels.push(String(params['id']));
      return undefined;
    });
    semer(['meyer', 'refus']);
    await monter([espion]);
    await screen.findByRole('button', { name: 'Annuler le classement de Sonia Meyer' });
    const avant = Number(/\d+/.exec(screen.getByRole('status', { name: 'Réponses à traiter' }).textContent ?? '')?.[0]);
    expect(avant).toBeGreaterThan(0);

    await user.click(screen.getByRole('button', { name: 'Annuler le classement de Sonia Meyer' }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByText('Le classement est annulé ; les arrêts et les tâches déjà créés sont conservés.')).toBeInTheDocument();
    await user.click(within(dialogue).getByRole('button', { name: 'Annuler le classement' }));

    await waitFor(() => expect(appels).toEqual(['rep-test-meyer']));
    await waitFor(() => expect(screen.queryByRole('listitem', { name: /Sonia Meyer/ })).not.toBeInTheDocument());
    const revenue = demoDb.reponses.trouver('rep-test-meyer');
    expect(revenue?.traitee).toBe(false);
    await waitFor(() => expect(screen.getByRole('status', { name: 'Réponses à traiter' })).toHaveTextContent(String(avant + 1)));
  });

  it('« Garder le classement » dans la confirmation n’appelle rien', async () => {
    const user = userEvent.setup();
    const appels: string[] = [];
    semer(['meyer', 'refus']);
    await monter([http.post(api('/envoi/reponses/:id/annuler'), ({ params }) => { appels.push(String(params['id'])); return undefined; })]);
    await user.click(await screen.findByRole('button', { name: 'Annuler le classement de Sonia Meyer' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Garder le classement' }));
    expect(appels).toHaveLength(0);
    expect(screen.getByRole('listitem', { name: /Sonia Meyer/ })).toBeInTheDocument();
  });

  it('ligne STOP : ni « Corriger » ni « Annuler », avec la phrase d’opposition définitive', async () => {
    semer(['perrin', 'stop']);
    await monter();
    await screen.findByRole('listitem', { name: /Hugo Perrin/ });
    const ligne = ligneDe('Hugo Perrin');
    expect(within(ligne).getByText('Opposition définitive : elle ne s’annule pas depuis cet écran.')).toBeInTheDocument();
    expect(within(ligne).queryByRole('button')).not.toBeInTheDocument();
  });

  it('409 « stop_definitif » : le message du serveur est affiché, la ligne reste (échec fermé)', async () => {
    const user = userEvent.setup();
    semer(['meyer', 'refus']);
    await monter([
      http.post(api('/envoi/reponses/:id/annuler'), () =>
        HttpResponse.json({ error: 'stop_definitif', message: 'Un STOP est définitif : il ne peut être ni annulé ni corrigé.' }, { status: 409 })),
    ]);
    await user.click(await screen.findByRole('button', { name: 'Annuler le classement de Sonia Meyer' }));
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Annuler le classement' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Un STOP est définitif : il ne peut être ni annulé ni corrigé.');
    expect(screen.getByRole('listitem', { name: /Sonia Meyer/ })).toBeInTheDocument();
    expect(demoDb.reponses.trouver('rep-test-meyer')?.traitee).toBe(true);
  });

  it('démo : le serveur refuse d’annuler un STOP (409) et une réponse non traitée (409)', async () => {
    semer(['perrin', 'stop']);
    demoDb.reponses.ajouter({ ...(demoDb.reponses.trouver('rep-test-perrin') as Reponse), id: 'rep-test-attente', classement: 'question', traitee: false });
    await monter();
    await screen.findByText('Opposition définitive : elle ne s’annule pas depuis cet écran.');
    const stop = await fetch('https://api.localhost/api/v1/envoi/reponses/rep-test-perrin/annuler', { method: 'POST' });
    expect(stop.status).toBe(409);
    expect(((await stop.json()) as { error: string }).error).toBe('stop_definitif');
    const nonTraitee = await fetch('https://api.localhost/api/v1/envoi/reponses/rep-test-attente/annuler', { method: 'POST' });
    expect(nonTraitee.status).toBe(409);
    expect(((await nonTraitee.json()) as { error: string }).error).toBe('pas_traitee');
    expect(demoDb.reponses.trouver('rep-test-perrin')?.traitee).toBe(true);
  });
});
