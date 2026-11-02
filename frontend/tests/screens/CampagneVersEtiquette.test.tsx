/**
 * DM1-CIB — le bouton « Campagne vers cette étiquette » de la page Étiquettes.
 * Il ouvre l'assistant d'Envoi avec l'étiquette en cible ; il est ABSENT
 * quand Envoi n'est pas dans l'offre.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { TagsManagerPage } from '@/features/tags/TagsManagerPage';
import { commandes } from '@/features/tags/commandes';
import { handlers } from '@/demo/handlers/crm/ciblage.handlers';
import { renderScreen } from '../helpers/renderScreen';
import { FEATURES_LES_DEUX_PRODUITS, getJson } from '../msw/handlers';

describe('Campagne vers cette étiquette', () => {
  it('ouvre l’assistant d’Envoi avec l’étiquette en cible', async () => {
    const user = userEvent.setup();
    const vue = await renderScreen(<TagsManagerPage />, {
      path: '/tags',
      handlers: [getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlers],
      landingRoutes: ['/envoi/campagnes/nouvelle'],
    });

    const lien = await screen.findByRole('link', { name: 'Campagne vers cette étiquette' });
    expect(lien).toHaveAttribute('href', expect.stringContaining('/envoi/campagnes/nouvelle'));
    expect(lien).toHaveAttribute('href', expect.stringContaining('etiquette=salon-artisans-2026'));

    await user.click(lien);
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/envoi/campagnes/nouvelle'));
    expect(vue.router.state.location.search).toMatchObject({ etiquette: 'salon-artisans-2026' });
  });

  it('est absent quand Envoi est absent', async () => {
    await renderScreen(<TagsManagerPage />, { path: '/tags', handlers });
    await screen.findByRole('heading', { name: 'Étiquettes' });
    await screen.findAllByText('salon-artisans-2026');
    expect(screen.queryByRole('link', { name: /Campagne vers/ })).toBeNull();
    expect(screen.getByRole('button', { name: 'Nouvelle étiquette' })).toBeInTheDocument();
  });

  it('propose l’action Ctrl K « Lancer une campagne vers une étiquette »', () => {
    const c = commandes.find((x) => x.libelle === 'Lancer une campagne vers une étiquette');
    expect(c).toBeDefined();
    expect(c?.produit).toBe('envoi');
  });
});
