/**
 * PALETTE ⌘K — quand le serveur n'a cherché AUCUNE entreprise par son nom
 * (« SARL » seul : une forme juridique n'est jamais point d'entrée de la
 * recherche, #294), la palette le DIT au lieu d'un « aucun résultat » muet.
 */
import { describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { GlobalSearch } from '@/components/ui/GlobalSearch';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

describe('GlobalSearch — indice de la recherche d’entreprises', () => {
  it('« SARL » seul : aucun résultat, et la phrase qui dit quoi ajouter', async () => {
    await renderScreen(<GlobalSearch />, {
      handlers: [getJson('/search', { companies: [], contacts: [], tags: [], indice_entreprises: 'mots_vides' })],
    });

    await userEvent.click(screen.getByRole('button', { name: 'Recherche globale' }));
    await userEvent.type(screen.getByRole('combobox'), 'SARL');

    expect(
      await screen.findByText('Ajoutez un mot du nom de l’entreprise : « SARL » seul ne suffit pas.'),
    ).toBeVisible();
    expect(screen.getByText(/Aucun résultat pour « SARL »/)).toBeVisible();
  });

  it('TÉMOIN : sans indice, aucune phrase ajoutée', async () => {
    await renderScreen(<GlobalSearch />, {
      handlers: [getJson('/search', { companies: [], contacts: [], tags: [], indice_entreprises: null })],
    });

    await userEvent.click(screen.getByRole('button', { name: 'Recherche globale' }));
    await userEvent.type(screen.getByRole('combobox'), 'zzzz');

    expect(await screen.findByText(/Aucun résultat pour « zzzz »/)).toBeVisible();
    expect(screen.queryByText(/seul ne suffit pas/)).not.toBeInTheDocument();
  });
});
