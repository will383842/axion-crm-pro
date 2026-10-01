/**
 * EN-TÊTE — qui est connecté, dans quel espace (audit UX du 02/10/2026, P0-2).
 *
 * Constat en prod : la barre affichait « Mon workspace » (ou « Workspace
 * a1b2c3 », un morceau d'identifiant) et le menu « Utilisateur ». Le sélecteur
 * d'espace proposait deux entrées désactivées pour toujours.
 *
 * Ces gardes rougissent si :
 *  - le nom réel de l'espace n'est pas affiché alors que `/auth/me` le donne ;
 *  - « Mon workspace » / « Workspace » / un identifiant reviennent ;
 *  - le menu utilisateur affiche « Utilisateur » au lieu de l'adresse e-mail ;
 *  - une entrée désactivée (ou « Profil » en double) revient dans un menu.
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { WorkspaceSelector, libelleEspace } from '@/components/layout/WorkspaceSelector';
import { UserMenu } from '@/components/layout/UserMenu';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const ME_AVEC_ESPACE = {
  user: { id: 'u-1', name: 'Will Test', email: 'contact@axion-ia.com', current_workspace_id: 'a1b2c3d4-0000-4000-8000-000000000000' },
  workspace: { id: 'a1b2c3d4-0000-4000-8000-000000000000', name: 'Axion IA' },
};

describe('Espace courant (barre latérale)', () => {
  it('affiche le NOM réel de l’espace, jamais « Mon workspace » ni un identifiant', async () => {
    await renderScreen(<WorkspaceSelector />, { handlers: [getJson('/auth/me', ME_AVEC_ESPACE)] });

    await waitFor(() => {
      expect(screen.getByTestId('espace-courant')).toHaveTextContent('Axion IA');
    });
    const texte = screen.getByTestId('espace-courant').textContent ?? '';
    expect(texte).not.toMatch(/workspace/i);
    expect(texte).not.toContain('a1b2c3');
  });

  it('n’est plus un menu : aucune entrée désactivée (« Créer / Gérer les workspaces »)', async () => {
    await renderScreen(<WorkspaceSelector />, { handlers: [getJson('/auth/me', ME_AVEC_ESPACE)] });

    await waitFor(() => {
      expect(screen.getByTestId('espace-courant')).toHaveTextContent('Axion IA');
    });
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    expect(screen.queryByText(/Créer un workspace|Gérer les workspaces/)).not.toBeInTheDocument();
  });

  it('libellé : nom connu → nom ; espace sans nom → « Espace » ; aucun espace → le dire', () => {
    expect(libelleEspace(ME_AVEC_ESPACE)).toBe('Axion IA');
    expect(
      libelleEspace({ user: { id: 'u', current_workspace_id: 'ws-1' }, workspace: null }),
    ).toBe('Espace');
    expect(libelleEspace({ user: { id: 'u', current_workspace_id: null } })).toBe('Aucun espace actif');
    // En chargement : un libellé neutre, pas « Mon workspace ».
    expect(libelleEspace(undefined)).toBe('Espace');
  });
});

describe('Menu utilisateur (en-tête)', () => {
  it('nom vide : affiche l’ADRESSE E-MAIL, jamais « Utilisateur »', async () => {
    await renderScreen(<UserMenu />, {
      handlers: [
        getJson('/auth/me', {
          user: { id: 'u-1', name: '', email: 'contact@axion-ia.com', current_workspace_id: 'ws-1' },
        }),
      ],
    });

    await waitFor(() => {
      expect(screen.getByRole('button', { name: /Menu utilisateur/ })).toHaveAccessibleName(
        'Menu utilisateur — contact@axion-ia.com',
      );
    });
    expect(screen.queryByText('Utilisateur')).not.toBeInTheDocument();
  });

  it('nom connu : affiche le nom', async () => {
    await renderScreen(<UserMenu />, { handlers: [getJson('/auth/me', ME_AVEC_ESPACE)] });

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Menu utilisateur — Will Test' })).toBeVisible();
    });
  });

  it('le menu ne contient ni entrée désactivée, ni « Profil » en double de « Paramètres »', async () => {
    const user = userEvent.setup();
    await renderScreen(<UserMenu />, { handlers: [getJson('/auth/me', ME_AVEC_ESPACE)] });

    await user.click(await screen.findByRole('button', { name: /Menu utilisateur/ }));

    const entrees = await screen.findAllByRole('menuitem');
    expect(entrees.map((e) => e.textContent?.trim())).toEqual(['Paramètres', 'Déconnexion']);
    for (const entree of entrees) {
      expect(entree).not.toBeDisabled();
      expect(entree).not.toHaveAttribute('aria-disabled', 'true');
    }
  });
});
