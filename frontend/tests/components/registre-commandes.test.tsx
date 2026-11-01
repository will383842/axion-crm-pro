/**
 * DM0-COQ — CTRL K : LES ACTIONS DES LOTS, CHARGÉES TOUTES SEULES.
 *
 * Chaque lot d'écran ajoute ses actions dans SON fichier
 * `src/features/<produit>/<zone>/commandes.ts` (`export const commandes`).
 * `src/features/socle/commandes/registre.ts` les charge par `import.meta.glob` :
 * aucun lot n'a à toucher `GlobalSearch.tsx`.
 *
 * Ce que la garde tient :
 *  1. le registre lit un module `commandes.ts` et en garde chaque commande ;
 *  2. un module sans export `commandes`, ou une commande sans `aller` ni
 *     `agir`, est refusé (erreur nommée, pas un oubli silencieux) ;
 *  3. le VRAI chargement par glob trouve le fichier du socle ;
 *  4. Ctrl K affiche une commande avec sa MARQUE DE PRODUIT, et la sélectionner
 *     y mène ;
 *  5. une commande d'Envoi n'apparaît pas quand Envoi est absent.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import {
  chargerCommandes,
  commandesDuRegistre,
  filtrerCommandes,
  type CommandeRecherche,
} from '@/features/socle/commandes/registre';
import { GlobalSearch } from '@/components/ui';
import { renderScreen } from '../helpers/renderScreen';
import { FEATURES_LES_DEUX_PRODUITS, getJson, handlersEnvoi } from '../msw/handlers';

/** Un `commandes.ts` de test, tel que `import.meta.glob(…, { eager: true })` le rend. */
const MODULE_ENVOI_DE_TEST = {
  commandes: [
    { id: 'essai-chercher', libelle: 'Chercher un envoi d’essai', produit: 'envoi', motsCles: ['adresse', 'trace'], aller: '/envoi/sante/chercher' },
  ] satisfies CommandeRecherche[],
};

const MODULE_DE_TEST: Record<string, { commandes?: CommandeRecherche[] }> = {
  '../../envoi/essai/commandes.ts': MODULE_ENVOI_DE_TEST,
  '../../crm/essai/commandes.ts': {
    commandes: [
      { id: 'essai-contact', libelle: 'Ajouter un contact d’essai', produit: 'crm', motsCles: ['personne'], aller: '/contacts' },
    ],
  },
};

beforeEach(() => {
  window.sessionStorage.clear();
});

describe('Registre des commandes — chargement', () => {
  it('lit chaque module `commandes.ts` et garde toutes ses commandes', () => {
    const commandes = chargerCommandes(MODULE_DE_TEST);
    expect(commandes.map((c) => c.id).sort()).toEqual(['essai-chercher', 'essai-contact']);
  });

  it('refuse un module sans export `commandes`', () => {
    expect(() => chargerCommandes({ '../../envoi/vide/commandes.ts': {} })).toThrow(/commandes/);
  });

  it('refuse une commande sans `aller` ni `agir`', () => {
    expect(() =>
      chargerCommandes({
        '../../envoi/x/commandes.ts': { commandes: [{ id: 'x', libelle: 'X', produit: 'envoi', motsCles: [] }] },
      }),
    ).toThrow(/aller|agir/);
  });

  it('refuse deux commandes de même identifiant', () => {
    expect(() => chargerCommandes({ ...MODULE_DE_TEST, '../../envoi/autre/commandes.ts': MODULE_ENVOI_DE_TEST })).toThrow(/essai-chercher/);
  });

  it('le VRAI chargement (`import.meta.glob`) trouve les commandes du socle', () => {
    const ids = commandesDuRegistre().map((c) => c.id);
    expect(ids.length).toBeGreaterThan(0);
    expect(ids).toContain('socle-envoi-campagnes');
  });

  it('filtre par libellé ou mot-clé, sans tenir compte des accents ni de la casse', () => {
    const commandes = chargerCommandes(MODULE_DE_TEST);
    const tout = { crm: true, envoi: true };
    expect(filtrerCommandes(commandes, 'ESSAI', tout).length).toBe(2);
    expect(filtrerCommandes(commandes, 'trace', tout).map((c) => c.id)).toEqual(['essai-chercher']);
    expect(filtrerCommandes(commandes, 'personne', tout).map((c) => c.id)).toEqual(['essai-contact']);
    // Envoi absent : ses commandes disparaissent.
    expect(filtrerCommandes(commandes, 'essai', { crm: true, envoi: false }).map((c) => c.id)).toEqual(['essai-contact']);
  });
});

describe('Ctrl K — les commandes apparaissent avec leur marque de produit', () => {
  it('une commande d’Envoi est affichée, marquée « Envoi », et y mène', async () => {
    const vue = await renderScreen(<GlobalSearch />, {
      path: '/companies',
      handlers: [
        getJson('/config/features', FEATURES_LES_DEUX_PRODUITS),
        getJson('/search', { companies: [], contacts: [], tags: [] }),
        ...handlersEnvoi(),
      ],
      landingRoutes: ['/envoi/campagnes'],
    });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));

    await userEvent.click(screen.getByRole('button', { name: 'Recherche globale' }));
    await userEvent.type(screen.getByPlaceholderText(/rechercher/i), 'campagnes');

    const option = await screen.findByRole('option', { name: /Ouvrir les campagnes/ });
    const marque = within(option).getByText('Envoi');
    expect(marque).toHaveAttribute('data-produit', 'envoi');
    expect(marque).toHaveAttribute('data-marque-produit');

    await userEvent.click(option);
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/envoi/campagnes'));
  });

  it('sans Envoi (défaut), la commande d’Envoi n’apparaît pas', async () => {
    const vue = await renderScreen(<GlobalSearch />, {
      path: '/companies',
      handlers: [getJson('/search', { companies: [], contacts: [], tags: [] })],
    });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    await userEvent.click(screen.getByRole('button', { name: 'Recherche globale' }));
    await userEvent.type(screen.getByPlaceholderText(/rechercher/i), 'campagnes');
    expect(await screen.findByText(/aucun résultat/i)).toBeInTheDocument();
    expect(screen.queryByRole('option', { name: /Ouvrir les campagnes/ })).toBeNull();
  });
});
