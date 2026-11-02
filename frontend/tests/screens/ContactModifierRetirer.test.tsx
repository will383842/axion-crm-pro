/**
 * DM1-CON — modifier, retirer (corbeille restaurable) et fiche d'une personne.
 *
 * Règle de fer n° 2 : on ne supprime JAMAIS une personne. Retirer place en
 * corbeille, « Restaurer » la rend ; aucune requête DELETE ne part.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { PersonTimelinePage } from '@/features/crm-console/PersonTimelinePage';
import { CorbeilleContacts } from '@/features/contacts/corbeille/CorbeilleContacts';
import { cleDePersonne } from '@/demo/donnees/crm/contacts';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/crm/contacts.handlers';
import { renderScreen } from '../helpers/renderScreen';
import { server } from '../msw/server';

const PATH = '/console/personnes/$personKey';

function idDe(prenom: string, nom: string): number {
  const c = demoDb.contacts.tous().find((x) => x.first_name === prenom && x.last_name === nom);
  if (c === undefined) throw new Error(`Contact de démo introuvable : ${prenom} ${nom}`);
  return c.id;
}

async function ouvrirFiche(prenom: string, nom: string) {
  return renderScreen(<PersonTimelinePage />, {
    path: PATH,
    url: `/console/personnes/${cleDePersonne(idDe(prenom, nom))}`,
    consoleFeatures: 'open',
    handlers,
  });
}

describe('fiche contact — modifier et retirer', () => {
  const methodes: string[] = [];
  beforeEach(() => {
    demoDb.reinitialiser();
    methodes.length = 0;
    server.events.removeAllListeners('request:start');
    server.events.on('request:start', ({ request }) => {
      methodes.push(`${request.method} ${new URL(request.url).pathname}`);
    });
  });

  it('retirer place la fiche en corbeille, « Restaurer » la rend, et aucun DELETE ne part', async () => {
    const user = userEvent.setup();
    await ouvrirFiche('Paul', 'Morin');

    await user.click(await screen.findByRole('button', { name: 'Retirer' }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByText(/restaurer/i)).toBeInTheDocument();
    await user.click(within(dialogue).getByRole('button', { name: 'Retirer de la base' }));

    const bandeau = await screen.findByRole('status', { name: 'Fiche dans la corbeille' });
    await user.click(within(bandeau).getByRole('button', { name: 'Restaurer' }));
    await waitFor(() => expect(screen.queryByRole('status', { name: 'Fiche dans la corbeille' })).not.toBeInTheDocument());

    expect(methodes.some((m) => m.startsWith('DELETE'))).toBe(false);
    expect(methodes.some((m) => m.endsWith('/retirer'))).toBe(true);
    expect(methodes.some((m) => m.endsWith('/restaurer'))).toBe(true);
    // La personne existe toujours, intacte.
    expect(demoDb.contacts.trouver(idDe('Paul', 'Morin'))).toBeDefined();
  });

  it('la corbeille liste les fiches retirées et les restaure', async () => {
    const user = userEvent.setup();
    await ouvrirFiche('Julie', 'Morin');
    await user.click(await screen.findByRole('button', { name: 'Retirer' }));
    await user.click(await within(await screen.findByRole('dialog')).findByRole('button', { name: 'Retirer de la base' }));
    await screen.findByRole('status', { name: 'Fiche dans la corbeille' });

    await renderScreen(<CorbeilleContacts open onClose={() => undefined} />, {
      path: '/console/contacts',
      url: '/console/contacts',
      consoleFeatures: 'open',
      handlers,
    });
    const corbeille = (await screen.findByRole('heading', { name: 'Corbeille' })).closest('[role="dialog"]') as HTMLElement;
    expect(await within(corbeille).findByText('Julie Morin')).toBeInTheDocument();
    await user.click(within(corbeille).getByRole('button', { name: 'Restaurer Julie Morin' }));
    await waitFor(() => expect(within(corbeille).queryByText('Julie Morin')).not.toBeInTheDocument());
  });

  it('modifier change la fonction de la personne', async () => {
    const user = userEvent.setup();
    await ouvrirFiche('Paul', 'Morin');
    await user.click(await screen.findByRole('button', { name: 'Modifier' }));
    const dialogue = await screen.findByRole('dialog');
    const champ = within(dialogue).getByLabelText('Fonction');
    await user.clear(champ);
    await user.type(champ, 'Directeur général');
    await user.click(within(dialogue).getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    expect(await screen.findByText(/Directeur général/)).toBeInTheDocument();
    expect(demoDb.zone<{ id: number; fonction: string }>('contacts', 'fiches').trouver(idDe('Paul', 'Morin'))?.fonction).toBe('Directeur général');
  });
});

describe('fiche contact — état de chaque envoi', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('un refus « Adresse inexistante » s’affiche en français avec sa date (Yves Roche)', async () => {
    await ouvrirFiche('Yves', 'Roche');
    const section = await screen.findByRole('region', { name: 'État de ses messages (Envoi)' });
    const ligne = within(section).getByText('Adresse inexistante').closest('tr');
    expect(ligne).not.toBeNull();
    expect(within(ligne as HTMLElement).getByText('Refusé')).toBeInTheDocument();
    expect(ligne).toHaveTextContent(/\d{2}\/\d{2}\/\d{4}/);
    // Et on ne peut pas lui écrire.
    expect(screen.getByRole('region', { name: 'Peut-on lui écrire ?' })).toHaveTextContent(/Non/);
  });

  it('Paul Morin : réponse intéressée dans l’historique, joignable', async () => {
    await ouvrirFiche('Paul', 'Morin');
    expect(await screen.findByText(/Réponse : « Bonjour/)).toBeInTheDocument();
    expect(screen.getByRole('region', { name: 'Peut-on lui écrire ?' })).toHaveTextContent(/Oui/);
  });

  it('noter un appel l’ajoute à l’historique, et un rappel peut être planifié', async () => {
    const user = userEvent.setup();
    await ouvrirFiche('Paul', 'Morin');
    await user.click(await screen.findByRole('button', { name: 'Noter un appel' }));
    const dialogue = await screen.findByRole('dialog');
    await user.type(within(dialogue).getByLabelText('Compte rendu'), 'Rappelé, rendez-vous pris');
    await user.click(within(dialogue).getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/Rappelé, rendez-vous pris/)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Planifier un rappel' }));
    const rappel = await screen.findByRole('dialog');
    await user.type(within(rappel).getByLabelText('Date du rappel'), '2027-01-14');
    await user.click(within(rappel).getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/Rappel prévu le 14\/01\/2027/)).toBeInTheDocument();
  });
});
