/**
 * DM2-CAM — liste des campagnes d'Envoi : filtres, tuiles, et les quatre gestes
 * de chaque ligne (Pause / Reprendre, Changer la date, Dupliquer, Terminer),
 * tous avec confirmation. Le faux serveur est celui de la démo (`handlers`).
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { CampagnesPage } from '@/features/envoi/campagnes/CampagnesPage';
import { handlers } from '@/demo/handlers/envoi/campagnes.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

async function ouvrir(url = '/envoi/campagnes') {
  return renderScreen(<CampagnesPage />, {
    path: '/envoi/campagnes',
    url,
    handlers,
    landingRoutes: ['/envoi/campagnes/nouvelle', '/envoi/campagnes/$campagneId'],
  });
}

const ligne = async (nom: RegExp) => (await screen.findByRole('row', { name: nom }));

afterEach(() => definirEtatForce(null));

describe('CampagnesPage — liste', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
  });

  it('affiche les campagnes, le compte par statut et les tuiles', async () => {
    await ouvrir();
    expect(await screen.findByRole('heading', { level: 1, name: 'Campagnes' })).toBeInTheDocument();
    expect(await ligne(/Organisateurs Rhône-Alpes/)).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /En cours\s*2/ })).toBeInTheDocument();
    expect(screen.getByRole('tab', { name: /Brouillons\s*2/ })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Nouvelle campagne' })).toBeInTheDocument();
  });

  it('filtre par statut', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await ligne(/Organisateurs Rhône-Alpes/);
    await user.click(screen.getByRole('tab', { name: /En pause/ }));
    expect(await ligne(/Commerces de Vienne/)).toBeInTheDocument();
    await waitFor(() => expect(screen.queryByRole('row', { name: /Organisateurs Rhône-Alpes/ })).not.toBeInTheDocument());
  });

  it('Pause depuis la ligne : 2 clics et une confirmation', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const org = await ligne(/Organisateurs Rhône-Alpes/);
    await user.click(within(org).getByRole('button', { name: /^Pause/ }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Mettre en pause ?' })).toBeInTheDocument();
    expect(dialogue).toHaveTextContent('Rien n’est perdu');
    await user.click(within(dialogue).getByRole('button', { name: 'Mettre en pause' }));
    await waitFor(() => expect(demoDb.campagnes.trouver('cmp-01')?.statut).toBe('en_pause'));
    const apres = await ligne(/Organisateurs Rhône-Alpes/);
    expect(within(apres).getByText('En pause')).toBeInTheDocument();
    expect(within(apres).getByRole('button', { name: /^Reprendre/ })).toBeInTheDocument();
  });

  it('Annuler la pause ne change rien', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const org = await ligne(/Organisateurs Rhône-Alpes/);
    await user.click(within(org).getByRole('button', { name: /^Pause/ }));
    await user.click(await screen.findByRole('button', { name: 'Annuler' }));
    expect(demoDb.campagnes.trouver('cmp-01')?.statut).toBe('en_cours');
  });

  it('Reprendre remet la campagne en cours', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const vienne = await ligne(/Commerces de Vienne/);
    await user.click(within(vienne).getByRole('button', { name: /^Reprendre/ }));
    await waitFor(() => expect(demoDb.campagnes.trouver('cmp-04')?.statut).toBe('en_cours'));
  });

  it('Changer la date : « Demain 9:00 » en un clic', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const federations = await ligne(/Fédérations — début d’année/);
    await user.click(within(federations).getByRole('button', { name: /^Changer la date/ }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Changer la date' })).toBeInTheDocument();
    await user.click(within(dialogue).getByRole('button', { name: 'Demain 9:00' }));
    await user.click(within(dialogue).getByRole('button', { name: 'Reprogrammer' }));
    // Aujourd'hui de la démo : mardi 12 janvier 2027 ; demain 9:00 à Paris = 08:00 UTC.
    await waitFor(() => expect(demoDb.campagnes.trouver('cmp-02')?.prochain_envoi_le).toBe('2027-01-13T08:00:00.000Z'));
  });

  it('Dupliquer crée « … (copie) » en brouillon', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const avant = demoDb.campagnes.compter();
    const org = await ligne(/Organisateurs Rhône-Alpes/);
    await user.click(within(org).getByRole('button', { name: /^Dupliquer/ }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Dupliquer la campagne' })).toBeInTheDocument();
    expect(within(dialogue).getByRole('textbox', { name: 'Nom de la copie' })).toHaveValue('Organisateurs Rhône-Alpes (copie)');
    await user.click(within(dialogue).getByRole('button', { name: 'Créer la copie (brouillon)' }));
    await waitFor(() => expect(demoDb.campagnes.compter()).toBe(avant + 1));
    const copie = demoDb.campagnes.tous().find((c) => c.nom === 'Organisateurs Rhône-Alpes (copie)');
    expect(copie?.statut).toBe('brouillon');
    expect(copie?.envoyes).toBe(0);
    expect(await ligne(/Organisateurs Rhône-Alpes \(copie\)/)).toBeInTheDocument();
  });

  it('Terminer est définitif et demande confirmation', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const vienne = await ligne(/Commerces de Vienne/);
    await user.click(within(vienne).getByRole('button', { name: /^Terminer/ }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Terminer définitivement ?' })).toBeInTheDocument();
    expect(dialogue).toHaveTextContent('On ne peut pas la relancer');
    expect(demoDb.campagnes.trouver('cmp-04')?.statut).toBe('en_pause');
    await user.click(within(dialogue).getByRole('button', { name: 'Terminer la campagne' }));
    await waitFor(() => expect(demoDb.campagnes.trouver('cmp-04')?.statut).toBe('terminee'));
    const apres = await ligne(/Commerces de Vienne/);
    expect(within(apres).queryByRole('button', { name: /^(Pause|Reprendre|Terminer|Changer la date)/ })).not.toBeInTheDocument();
    expect(within(apres).getByRole('button', { name: /^Dupliquer/ })).toBeInTheDocument();
  });

  it('n’affiche ni clic ni colonne d’ouverture dans la liste', async () => {
    await ouvrir();
    await ligne(/Organisateurs Rhône-Alpes/);
    expect(screen.queryByRole('columnheader', { name: /ouverture|clic/i })).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/\bclics?\b/i);
  });

  it('état vide : propose de créer une campagne', async () => {
    definirEtatForce('vide');
    await ouvrir();
    expect(await screen.findByText('Aucune campagne pour l’instant')).toBeInTheDocument();
  });

  it('état d’erreur : message en français et bouton Réessayer', async () => {
    definirEtatForce('erreur');
    await ouvrir();
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });
});
