/**
 * DM2-INF — fiche d'un domaine : 30 jours, DMARC progressif, listes noires.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DomainePage } from '@/features/envoi/infrastructure/DomainePage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/infrastructure.handlers';
import { renderScreen } from '../../helpers/renderScreen';

async function monter(id: string, nom: string) {
  const view = await renderScreen(<DomainePage />, {
    path: '/envoi/domaines/$domaineId',
    url: `/envoi/domaines/${id}`,
    handlers,
  });
  await screen.findByRole('heading', { level: 1, name: nom });
  return view;
}

describe('DomainePage', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('montre le graphique des 30 derniers jours avec son équivalent en texte', async () => {
    await monter('dom-a', 'prospection-a.example');
    const graphique = await screen.findByRole('img', { name: /30 derniers jours/i });
    expect(graphique).toBeInTheDocument();
    expect(screen.getByText(/Envoyés \(30 j\)/)).toBeInTheDocument();
    // Équivalent texte : un tableau pour les lecteurs d'écran, 30 lignes de jours.
    const tableau = screen.getByRole('table', { name: /30 derniers jours/i });
    expect(within(tableau).getAllByRole('row')).toHaveLength(31);
  });

  it('affiche la pastille DMARC avec la date de la prochaine étape, et la fait avancer', async () => {
    const u = userEvent.setup();
    await monter('dom-b', 'prospection-b.example');
    expect(await screen.findByText('passer à quarantaine le 26 janv.')).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: /Passer à « quarantaine » maintenant/ }));
    await waitFor(() => expect(demoDb.domaines.trouver('dom-b')?.dmarc).toBe('quarantine'));
    expect(await screen.findByText('passer à rejet le 9 févr.')).toBeInTheDocument();
  });

  it('montre la fiche « que faire » d’une liste noire, avec le lien de demande de retrait', async () => {
    await monter('dom-b', 'prospection-b.example');
    const fiche = await screen.findByRole('region', { name: /Liste B \(spécialisée\)/ });
    expect(within(fiche).getByText(/Présent sur la liste/)).toBeInTheDocument();
    expect(within(fiche).getByText(/Que faire/)).toBeInTheDocument();
    const lien = within(fiche).getByRole('link', { name: /Demander le retrait/ });
    expect(lien).toHaveAttribute('href', expect.stringMatching(/^https:\/\/[\w.-]+\.example\//));
  });

  it('« Vérifier les DNS » fait passer les enregistrements absents au vert', async () => {
    const u = userEvent.setup();
    await monter('dom-d', 'prospection-d.example');
    expect(await screen.findByText('DNS 2/5')).toBeInTheDocument();
    await u.click(screen.getByRole('button', { name: 'Vérifier les DNS' }));
    expect(await screen.findByText('DNS 5/5')).toBeInTheDocument();
  });
});
