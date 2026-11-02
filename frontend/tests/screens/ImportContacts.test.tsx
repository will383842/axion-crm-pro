/**
 * DM1-CON — import de contacts en 3 étapes : colonnes, contrôle, rapport.
 *
 * Règle d'or : AUCUNE fiche n'est créée sans provenance. Le rapport dit ce qui
 * a été refusé, ce qui est en doublon et ce qui n'a pas de provenance.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ImportContacts } from '@/features/contacts/import/ImportContacts';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/crm/contacts.handlers';
import { renderScreen } from '../helpers/renderScreen';

const PATH = '/console/contacts';

/** 100 lignes : 70 bonnes, 10 e-mails invalides, 5 doublons, 15 sans provenance. */
function csvDeCentLignes(): string {
  const lignes = ['Prénom;Nom;E-mail;Organisation;Fonction;Provenance'];
  for (let i = 1; i <= 70; i += 1) lignes.push(`Prénom${i};Nom${i};import${i}@example.test;Atelier Import ${i};Gérant;salon`);
  for (let i = 1; i <= 10; i += 1) lignes.push(`Faux${i};Mail${i};pas-un-email-${i};Atelier Faux ${i};Gérant;salon`);
  for (let i = 1; i <= 5; i += 1) lignes.push(`Paul;Morin;paul.morin@boulangerie-tilleuls.example;Boulangerie des Tilleuls;Gérant;salon`);
  for (let i = 1; i <= 15; i += 1) lignes.push(`Sans${i};Source${i};sans${i}@example.test;Atelier Sans ${i};Gérant;`);
  return lignes.join('\n');
}

function fichier(contenu: string, nom = 'contacts-factices.csv'): File {
  return new File([contenu], nom, { type: 'text/csv' });
}

async function monter() {
  return renderScreen(<ImportContacts open onClose={() => undefined} intervalleMs={5} />, {
    path: PATH,
    url: PATH,
    consoleFeatures: 'open',
    handlers,
  });
}

describe('ImportContacts', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('fichier factice de 100 lignes : le rapport donne refusées, doublons et sans provenance', async () => {
    const user = userEvent.setup();
    await monter();

    await user.upload(await screen.findByLabelText(/Fichier à importer/), fichier(csvDeCentLignes()));
    // Étape 1 : les colonnes sont reconnues.
    expect(await screen.findByText(/100 lignes lues/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Contrôler le fichier' }));

    // Étape 2 : le contrôle annonce le résultat AVANT d'écrire quoi que ce soit.
    expect(await screen.findByText(/70 fiches seront créées/)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Lancer l’import' }));

    // Étape 3 : le rapport.
    const rapport = await screen.findByRole('region', { name: 'Rapport d’import' });
    expect(within(rapport).getByText(/70 fiches créées/)).toBeInTheDocument();
    expect(within(rapport).getByText(/10 lignes refusées/)).toBeInTheDocument();
    expect(within(rapport).getByText(/5 doublons/)).toBeInTheDocument();
    expect(within(rapport).getByText(/15 lignes sans provenance/)).toBeInTheDocument();
  });

  it('aucune fiche n’est créée sans provenance', async () => {
    const user = userEvent.setup();
    await monter();
    const avant = demoDb.zone<{ id: number }>('contacts', 'fiches').compter();

    await user.upload(await screen.findByLabelText(/Fichier à importer/), fichier(csvDeCentLignes()));
    await user.click(await screen.findByRole('button', { name: 'Contrôler le fichier' }));
    await user.click(await screen.findByRole('button', { name: 'Lancer l’import' }));
    await screen.findByRole('region', { name: 'Rapport d’import' });

    const fiches = demoDb.zone<{ id: number; provenance: string }>('contacts', 'fiches');
    expect(fiches.compter() - avant).toBe(70);
    expect(fiches.tous().filter((f) => f.provenance.trim() === '')).toEqual([]);
    expect(demoDb.zone<{ id: number }>('contacts', 'fiches').tous().some((f) => 'email' in f && String((f as { email: string }).email).startsWith('sans'))).toBe(false);
  });

  it('un gros import tourne en arrière-plan avec une barre d’avancement', async () => {
    const user = userEvent.setup();
    await monter();
    const lignes = ['Prénom;Nom;E-mail;Organisation;Fonction;Provenance'];
    for (let i = 1; i <= 450; i += 1) lignes.push(`Gros${i};Import${i};gros${i}@example.test;Atelier Gros ${i};Gérant;fichier-client`);

    await user.upload(await screen.findByLabelText(/Fichier à importer/), fichier(lignes.join('\n')));
    await user.click(await screen.findByRole('button', { name: 'Contrôler le fichier' }));
    await user.click(await screen.findByRole('button', { name: 'Lancer l’import' }));

    expect(await screen.findByRole('progressbar', { name: /Import en cours/ })).toBeInTheDocument();
    const rapport = await screen.findByRole('region', { name: 'Rapport d’import' }, { timeout: 4000 });
    expect(within(rapport).getByText(/450 fiches créées/)).toBeInTheDocument();
  });

  it('un fichier Excel est expliqué, pas ignoré en silence', async () => {
    const user = userEvent.setup();
    await monter();
    await user.upload(await screen.findByLabelText(/Fichier à importer/), fichier('PK', 'contacts.xlsx'));
    await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent(/Excel/));
  });
});
