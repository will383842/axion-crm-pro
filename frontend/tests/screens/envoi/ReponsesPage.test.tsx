/**
 * DM2-REP — la boîte des réponses d'Envoi (démo).
 *
 *  1. « Appliquer la suggestion » crée la tâche CRM et range la réponse ;
 *  2. « STOP » demande confirmation, puis inscrit l'opposition (données gardées) ;
 *  3. « Envoyer la réponse » part dans le même fil (corps de la requête vérifié) ;
 *  4. raccourcis clavier J / K (et 1, 2, 3).
 *
 * Données FICTIVES (jeu de démo). Le faux serveur est celui de la démo.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, type HttpHandler } from 'msw';

import { ReponsesPage } from '@/features/envoi/reponses/ReponsesPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/reponses.handlers';
import { api } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

function reponsePaul() {
  const r = demoDb.reponses.filtrer((x) => x.prenom === 'Paul' && x.nom === 'Morin' && !x.traitee)[0];
  if (!r) throw new Error('La réponse de Paul Morin manque au jeu de démo.');
  return r;
}

async function monter(supplementaires: HttpHandler[] = []) {
  const vue = await renderScreen(<ReponsesPage />, {
    path: '/envoi/reponses',
    url: '/envoi/reponses',
    handlers: [...supplementaires, ...handlers],
  });
  return vue;
}

async function ouvrirPaul(user: ReturnType<typeof userEvent.setup>) {
  const liste = await screen.findByRole('list', { name: 'Réponses à traiter' });
  await user.click(await within(liste).findByRole('button', { name: /Paul Morin/ }));
  return screen.findByRole('article', { name: /Réponse de Paul Morin/ });
}

describe('ReponsesPage — boîte des réponses', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('« Appliquer la suggestion » crée la tâche CRM et range la réponse', async () => {
    const user = userEvent.setup();
    await monter();
    const detail = await ouvrirPaul(user);
    await user.click(within(detail).getByRole('button', { name: 'Appliquer la suggestion' }));

    const confirmation = await screen.findByRole('status', { name: 'Résumé envoyé au CRM' });
    expect(confirmation).toHaveTextContent('Tâche CRM');
    expect(demoDb.reponses.trouver(reponsePaulId())?.traitee).toBe(true);
    const liste = screen.getByRole('list', { name: 'Réponses à traiter' });
    await waitFor(() => expect(within(liste).queryByRole('button', { name: /Paul Morin/ })).not.toBeInTheDocument());
  });

  it('« STOP » demande confirmation, puis inscrit l’opposition sans rien supprimer', async () => {
    const user = userEvent.setup();
    await monter();
    const detail = await ouvrirPaul(user);
    const adresse = reponsePaul().de;
    const avant = demoDb.opposition.compter();

    await user.click(within(detail).getByRole('button', { name: /STOP/ }));
    const dialogue = await screen.findByRole('dialog', { name: /à l’écart des envois/ });
    // Annuler ne change rien.
    await user.click(within(dialogue).getByRole('button', { name: 'Annuler' }));
    expect(demoDb.opposition.compter()).toBe(avant);

    await user.click(within(detail).getByRole('button', { name: /STOP/ }));
    const dialogue2 = await screen.findByRole('dialog', { name: /à l’écart des envois/ });
    await user.click(within(dialogue2).getByRole('button', { name: 'Confirmer le STOP' }));

    await waitFor(() => expect(demoDb.opposition.compter()).toBe(avant + 1));
    const ligne = demoDb.opposition.tous().find((o) => o.valeur === adresse);
    expect(ligne?.source).toBe('reponse_stop');
    // Données gardées : la réponse existe toujours, rangée dans « Traitées ».
    const gardee = demoDb.reponses.trouver(reponsePaulId());
    expect(gardee).toBeDefined();
    expect(gardee?.classement).toBe('stop');
    expect(gardee?.traitee).toBe(true);
  });

  it('« Envoyer la réponse » part dans le même fil, avec le texte saisi', async () => {
    const user = userEvent.setup();
    const corpsRecus: unknown[] = [];
    const espion = http.post(api('/envoi/reponses/:id/repondre'), async ({ request }) => {
      corpsRecus.push(await request.clone().json());
      return undefined; // laisse le faux serveur de la démo répondre
    });
    await monter([espion]);
    const detail = await ouvrirPaul(user);

    await user.type(within(detail).getByRole('textbox', { name: 'Votre réponse' }), 'Bonjour Paul, avec plaisir.');
    await user.click(within(detail).getByRole('button', { name: 'Envoyer la réponse' }));

    await waitFor(() => expect(corpsRecus).toHaveLength(1));
    expect(corpsRecus[0]).toMatchObject({ corps: 'Bonjour Paul, avec plaisir.' });
    expect(await screen.findByText('Bonjour Paul, avec plaisir.')).toBeInTheDocument();
  });

  it('les raccourcis J et K passent d’une réponse à l’autre', async () => {
    const user = userEvent.setup();
    await monter();
    const liste = await screen.findByRole('list', { name: 'Réponses à traiter' });
    const boutons = await within(liste).findAllByRole('button');
    expect(boutons.length).toBeGreaterThan(2);
    expect(boutons[0]).toHaveAttribute('aria-current', 'true');

    await user.keyboard('j');
    await waitFor(() => expect(within(liste).getAllByRole('button')[1]).toHaveAttribute('aria-current', 'true'));
    await user.keyboard('k');
    await waitFor(() => expect(within(liste).getAllByRole('button')[0]).toHaveAttribute('aria-current', 'true'));
  });

  it('le raccourci 1 classe en « Pas intéressé »', async () => {
    const user = userEvent.setup();
    await monter();
    const detail = await ouvrirPaul(user);
    expect(detail).toBeInTheDocument();
    await user.keyboard('1');
    await waitFor(() => expect(demoDb.reponses.trouver(reponsePaulId())?.classement).toBe('refus'));
    expect(demoDb.reponses.trouver(reponsePaulId())?.traitee).toBe(true);
  });
});

function reponsePaulId(): string {
  const r = demoDb.reponses.filtrer((x) => x.prenom === 'Paul' && x.nom === 'Morin' && x.de.endsWith('.example') === false)[0]
    ?? demoDb.reponses.filtrer((x) => x.prenom === 'Paul' && x.nom === 'Morin')[0];
  if (!r) throw new Error('La réponse de Paul Morin manque au jeu de démo.');
  return String(r.id);
}
