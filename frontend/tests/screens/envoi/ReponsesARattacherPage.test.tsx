/**
 * DM2-REP — « Réponses à rattacher » : une réponse reçue d'une adresse inconnue.
 *
 *  1. rattacher à une fiche existante (la requête porte le bon type et la bonne référence) ;
 *  2. créer une nouvelle personne, avec sa provenance.
 *
 * Données FICTIVES (jeu de démo).
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, type HttpHandler } from 'msw';

import { ReponsesARattacherPage } from '@/features/envoi/reponses/ReponsesARattacherPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/reponses.handlers';
import { api } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

async function monter(supplementaires: HttpHandler[] = []) {
  return renderScreen(<ReponsesARattacherPage />, {
    path: '/envoi/reponses/a-rattacher',
    url: '/envoi/reponses/a-rattacher',
    handlers: [...supplementaires, ...handlers],
  });
}

const ADRESSE_INCONNUE = 'p.morin@boulangerie-tilleuls.example';

describe('ReponsesARattacherPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('propose des fiches et rattache la réponse à la fiche choisie', async () => {
    const user = userEvent.setup();
    const corpsRecus: unknown[] = [];
    const espion = http.post(api('/envoi/reponses/:id/rattacher'), async ({ request }) => {
      corpsRecus.push(await request.clone().json());
      return undefined;
    });
    await monter([espion]);

    const carte = await screen.findByRole('article', { name: new RegExp(ADRESSE_INCONNUE.replace('.', '\\.')) });
    expect(within(carte).getByText(/Je réponds depuis mon autre adresse/)).toBeInTheDocument();
    const propositions = await within(carte).findByRole('list', { name: 'Fiches proposées' });
    expect(within(propositions).getAllByRole('listitem').length).toBeGreaterThanOrEqual(2);

    const ligne = within(propositions).getByRole('listitem', { name: /Paul Morin/ });
    await user.click(within(ligne).getByRole('button', { name: /Rattacher/ }));

    await waitFor(() => expect(corpsRecus).toHaveLength(1));
    expect(corpsRecus[0]).toMatchObject({ type: 'contact' });
    expect((corpsRecus[0] as { reference: string }).reference).not.toBe('');

    // La réponse quitte « À rattacher » et rejoint « À traiter » : rien n'est perdu.
    const rattachee = demoDb.reponses.tous().find((r) => r.de === ADRESSE_INCONNUE);
    expect(rattachee).toBeDefined();
    expect(rattachee?.classement).not.toBe('a_rattacher');
    expect(rattachee?.traitee).toBe(false);
    await waitFor(() => expect(screen.queryByRole('article', { name: new RegExp(ADRESSE_INCONNUE.replace('.', '\\.')) })).not.toBeInTheDocument());
  });

  it('crée une nouvelle personne avec sa provenance', async () => {
    const user = userEvent.setup();
    await monter();
    const avant = demoDb.contacts.compter();

    const carte = await screen.findByRole('article', { name: new RegExp(ADRESSE_INCONNUE.replace('.', '\\.')) });
    await user.click(within(carte).getByRole('button', { name: 'Créer une nouvelle fiche dans le CRM' }));

    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Nouvelle fiche dans le CRM' })).toBeInTheDocument();
    await user.clear(within(dialogue).getByRole('textbox', { name: 'Prénom' }));
    await user.type(within(dialogue).getByRole('textbox', { name: 'Prénom' }), 'Pauline');
    await user.clear(within(dialogue).getByRole('textbox', { name: 'Nom' }));
    await user.type(within(dialogue).getByRole('textbox', { name: 'Nom' }), 'Morin');
    const provenance = within(dialogue).getByRole('textbox', { name: 'Provenance' });
    await user.clear(provenance);
    await user.type(provenance, 'Réponse transmise par un collègue');
    await user.click(within(dialogue).getByRole('button', { name: 'Créer la fiche' }));

    await waitFor(() => expect(demoDb.contacts.compter()).toBe(avant + 1));
    const creee = demoDb.contacts.tous().at(-1);
    expect(creee?.first_name).toBe('Pauline');
    expect(creee?.email).toBe(ADRESSE_INCONNUE);
    expect(demoDb.zone<{ id: string; contact_id: number; texte: string }>('reponses', 'provenances').tous()[0]?.texte).toBe(
      'Réponse transmise par un collègue',
    );
  });

  it('affiche l’état vide quand rien n’est à rattacher', async () => {
    await renderScreen(<ReponsesARattacherPage />, {
      path: '/envoi/reponses/a-rattacher',
      url: '/envoi/reponses/a-rattacher',
      handlers: [
        http.get(api('/envoi/reponses'), () =>
          Response.json({ data: [], meta: { page: 1, par_page: 25, total: 0, pages: 1 }, par_file: { a_traiter: 0, a_rattacher: 0, traitees: 0 } }),
        ),
        ...handlers,
      ],
    });
    expect(await screen.findByRole('heading', { name: 'Rien à rattacher' })).toBeInTheDocument();
  });
});
