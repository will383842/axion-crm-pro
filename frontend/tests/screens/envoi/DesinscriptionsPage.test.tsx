/**
 * DM2-CNF — « Désinscriptions » : le registre « ne plus écrire », la question
 * « Puis-je écrire à… ? » et l'export de preuve.
 *
 * Règle n° 2 : rien ne se supprime. L'écran ne propose donc AUCUN bouton
 * « Retirer de la liste ». Données fictives (dépôt public).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DesinscriptionsPage } from '@/features/envoi/conformite/DesinscriptionsPage';
import { handlers } from '@/demo/handlers/envoi/conformite.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

/** jsdom n'a pas `Blob.text()` : on lit le fichier comme le ferait un navigateur. */
function lireBlob(blob: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const lecteur = new FileReader();
    lecteur.onload = () => resolve(typeof lecteur.result === 'string' ? lecteur.result : '');
    lecteur.onerror = () => reject(new Error('lecture impossible'));
    lecteur.readAsText(blob);
  });
}

async function ouvrir(url = '/envoi/desinscriptions') {
  return renderScreen(<DesinscriptionsPage />, { path: '/envoi/desinscriptions', url, handlers });
}

describe('Désinscriptions (DM2-CNF)', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
  });

  it('affiche le registre des oppositions avec le nombre de lignes', async () => {
    await ouvrir();
    expect(await screen.findByRole('heading', { level: 1, name: 'Désinscriptions' })).toBeVisible();
    const premiere = demoDb.opposition.tous()[0]!;
    expect(await screen.findByText(premiere.valeur)).toBeVisible();
    expect(screen.getAllByText(/jamais effacées/i).length).toBeGreaterThan(0);
  });

  it('« Puis-je écrire à… ? » répond « non » avec le motif pour une adresse opposée', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const opposee = demoDb.opposition.tous().find((o) => o.portee === 'adresse')!;
    await user.type(await screen.findByRole('textbox', { name: 'Adresse ou domaine' }), opposee.valeur);
    await user.click(screen.getByRole('button', { name: 'Vérifier' }));
    const reponse = await screen.findByRole('status');
    expect(within(reponse).getByText('Non')).toBeVisible();
    // Contrat R05 : le serveur ne rend qu'un motif codé regroupé (ici « opposition »), jamais le détail de la ligne du registre.
    expect(within(reponse).getByText(/s’est opposée/i)).toBeVisible();
    // Aucune mention d’ouverture ni de pixel, jamais.
    expect(within(reponse).getByText(/aucun message ne part/i)).toBeVisible();
    expect(within(reponse).queryByText(/ouverture|pixel/i)).toBeNull();
  });

  it('« Puis-je écrire à… ? » répond « Oui » pour une adresse inconnue du registre', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await user.type(await screen.findByRole('textbox', { name: 'Adresse ou domaine' }), 'inconnu@nouveau-client.example');
    await user.click(screen.getByRole('button', { name: 'Vérifier' }));
    const reponse = await screen.findByRole('status');
    expect(within(reponse).getByText('Oui')).toBeVisible();
  });

  it('l’export produit un fichier CSV du registre', async () => {
    const user = userEvent.setup();
    const blobs: Blob[] = [];
    const creer = vi.fn((b: Blob) => {
      blobs.push(b);
      return 'blob:registre';
    });
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, writable: true, value: creer });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, writable: true, value: vi.fn() });
    const clic = vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
    await ouvrir();
    await user.click(await screen.findByRole('button', { name: /Exporter le registre/ }));
    await waitFor(() => expect(creer).toHaveBeenCalledTimes(1));
    expect(clic).toHaveBeenCalled();
    const texte = await lireBlob(blobs[0]!);
    expect(texte.split('\n')[0]).toContain('valeur');
    expect(texte).toContain(demoDb.opposition.tous()[0]!.valeur);
    clic.mockRestore();
  });

  it('ne propose aucun bouton « Retirer de la liste »', async () => {
    await ouvrir();
    await screen.findByText(demoDb.opposition.tous()[0]!.valeur);
    expect(screen.queryByRole('button', { name: /retirer|supprimer|effacer/i })).toBeNull();
  });

  it('inscrit une adresse « ne plus écrire » : elle apparaît dans le registre', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await user.click(await screen.findByRole('button', { name: /Ajouter au registre/ }));
    const dialogue = await screen.findByRole('dialog');
    await user.type(within(dialogue).getByRole('textbox', { name: 'Adresse ou domaine à inscrire' }), 'refus@client-test.example');
    await user.click(within(dialogue).getByRole('button', { name: 'Inscrire' }));
    expect(await screen.findByText('refus@client-test.example')).toBeVisible();
    expect(demoDb.opposition.tous().some((o) => o.valeur === 'refus@client-test.example')).toBe(true);
  });

  it('affiche l’état vide', async () => {
    definirEtatForce('vide');
    await ouvrir();
    expect(await screen.findByText('Rien à afficher pour le moment')).toBeVisible();
  });
});
