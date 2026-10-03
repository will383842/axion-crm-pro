/**
 * N13 — « PROPOSITIONS À VALIDER » (owner seul).
 *
 * Une ligne = la fiche, l'information concernée, la valeur actuelle → la
 * valeur proposée, qui la propose, et deux boutons. Ce fichier garde :
 *  - ce que montre une ligne, en mots simples (aucun code technique comme
 *    « apporteur » ou « city ») ;
 *  - que les boutons postent bien sur la bonne route ;
 *  - qu'un échec n'est pas présenté comme une file vide ;
 *  - que l'entrée du menu n'existe QUE pour le rôle owner.
 *
 * Fixtures FICTIVES (dépôt public).
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { PropositionsPage } from '@/features/propositions/PropositionsPage';
import { estOwner } from '@/features/propositions/useEstOwner';
import { sectionsDeNavigation } from '@/components/layout/Sidebar';
import { normaliserCompteurs, LIBELLES_COMPTEUR } from '@/features/a-traiter/compteurs';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, getStatus, recordPost } from '../msw/handlers';

const LIGNE = {
  id: 7,
  entite: 'entreprise',
  entite_id: 12,
  entreprise_id: 12,
  fiche: 'ZZ Boulangerie',
  champ: 'city',
  libelle_champ: 'Ville',
  valeur_actuelle: 'Lyon',
  valeur_proposee: 'Villeurbanne',
  origine: 'apporteur',
  recue_le: '2026-10-03T09:00:00Z',
};

const FILE = { data: [LIGNE], meta: { total: 1, per_page: 25, page: 1 } };

function texte(): string {
  return document.body.textContent ?? '';
}

describe('Propositions à valider', () => {
  it('une ligne dit la fiche, l’information, l’actuelle → la proposée, et qui propose', async () => {
    await renderScreen(<PropositionsPage />, {
      path: '/console/propositions',
      consoleFeatures: 'open',
      handlers: [getJson('/crm/propositions', FILE)],
    });

    await waitFor(() => expect(texte()).toContain('ZZ Boulangerie'));
    expect(texte()).toContain('Ville');
    expect(texte()).toContain('Lyon');
    expect(texte()).toContain('Villeurbanne');
    expect(texte()).toContain('Un apporteur d’affaires');
    expect(texte()).toContain('1 proposition à valider');
    // Pas de jargon technique à l'écran.
    expect(texte()).not.toMatch(/\bcity\b|\bapporteur\b(?! d)/);
    expect(screen.getByRole('button', { name: /Accepter : Ville de ZZ Boulangerie/ })).toBeTruthy();
    expect(screen.getByRole('button', { name: /Refuser : Ville de ZZ Boulangerie/ })).toBeTruthy();
  });

  it('Accepter et Refuser postent sur la route de la proposition', async () => {
    const accepter = recordPost('/crm/propositions/7/accepter', { id: 7, statut: 'acceptee' });
    const refuser = recordPost('/crm/propositions/7/refuser', { id: 7, statut: 'refusee' });
    await renderScreen(<PropositionsPage />, {
      path: '/console/propositions',
      consoleFeatures: 'open',
      handlers: [getJson('/crm/propositions', FILE), accepter.handler, refuser.handler],
    });

    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /Accepter :/ }));
    await waitFor(() => expect(accepter.bodies).toHaveLength(1));
    await user.click(await screen.findByRole('button', { name: /Refuser :/ }));
    await waitFor(() => expect(refuser.bodies).toHaveLength(1));
  });

  it('un refus du serveur n’est pas présenté comme une file vide', async () => {
    await renderScreen(<PropositionsPage />, {
      path: '/console/propositions',
      consoleFeatures: 'open',
      handlers: [getStatus('/crm/propositions', 403)],
    });

    await waitFor(() => expect(texte()).not.toContain('Chargement'));
    await waitFor(() => expect(texte()).not.toContain('Aucune proposition à valider'));
  });

  it('l’entrée du menu « Propositions à valider » n’existe que pour le rôle owner', () => {
    const features = { console_v2: true, universes: { business: true, vivier: false } };
    const entrees = (owner: boolean) =>
      sectionsDeNavigation(features, owner)
        .flatMap((s) => s.items)
        .map((i) => i.label);

    expect(entrees(true)).toContain('Propositions à valider');
    expect(entrees(false)).not.toContain('Propositions à valider');
    expect(estOwner({ roles: ['owner'] })).toBe(true);
    expect(estOwner({ roles: ['admin'] })).toBe(false);
    expect(estOwner(undefined)).toBe(false);
  });

  it('le compteur : absent si le serveur ne l’envoie pas, null pour un autre rôle', () => {
    expect(normaliserCompteurs({ doublons: 1, a_rattacher: 2 })).toEqual({ doublons: 1, a_rattacher: 2 });
    expect(normaliserCompteurs({ doublons: 1, a_rattacher: 2, propositions: null })).toEqual({
      doublons: 1,
      a_rattacher: 2,
      propositions: null,
    });
    expect(normaliserCompteurs({ doublons: 0, a_rattacher: 0, propositions: 3 }).propositions).toBe(3);
    expect(LIBELLES_COMPTEUR.propositions(1)).toBe('1 proposition à valider');
  });
});
