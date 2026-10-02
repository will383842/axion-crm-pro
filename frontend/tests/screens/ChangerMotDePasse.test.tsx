/**
 * « Mon compte → Changer mon mot de passe » et aide de l'écran de connexion —
 * constat prod du 2026-10-02.
 *
 * Le propriétaire n'avait AUCUN moyen de changer son mot de passe une fois
 * connecté, et l'écran de connexion ne disait rien quand le gestionnaire du
 * navigateur pré-remplissait l'ancien mot de passe.
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { SettingsPage } from '@/features/settings/SettingsPage';
import { LoginPage } from '@/features/auth/LoginPage';
import { renderScreen, type RenderScreenOptions } from '../helpers/renderScreen';
import { getJson, postStatus, recordPost } from '../msw/handlers';

const WORKSPACE = { id: 'ws-1', name: 'Axion IA', slug: 'axion-ia', cost_cap_eur: 50, settings: {} };
const NOUVEAU = 'NouveauMotDePasse-2026';

function etat(requis: boolean) {
  return getJson('/auth/password/change', {
    email: 'contact@axion-ia.com',
    mot_de_passe_actuel_requis: requis,
    minutes_restantes_sans_ancien: requis ? null : 25,
    a_un_mot_de_passe: true,
  });
}

async function ouvrirMonCompte(handlers: NonNullable<RenderScreenOptions['handlers']>) {
  await renderScreen(<SettingsPage />, { path: '/settings', handlers });
  const user = userEvent.setup();
  await user.click(await screen.findByRole('tab', { name: /Mon compte/ }));
  return user;
}

describe('Paramètres → Mon compte → Changer mon mot de passe', () => {
  it('affiche les règles en clair et exige l’ancien mot de passe par défaut', async () => {
    const { handler, bodies } = recordPost<Record<string, string>>('/auth/password/change', { changed: true });
    const user = await ouvrirMonCompte([getJson('/workspace', WORKSPACE), etat(true), handler]);

    expect(await screen.findByText(/12 caractères minimum\./)).toBeVisible();
    expect(screen.getByText(/fuite de données connue/)).toBeVisible();

    const actuel = await screen.findByLabelText('Mot de passe actuel');
    expect(actuel).toHaveAttribute('autocomplete', 'current-password');
    const nouveau = screen.getByLabelText('Nouveau mot de passe');
    expect(nouveau).toHaveAttribute('autocomplete', 'new-password');
    expect(screen.getByLabelText('Confirmation du nouveau mot de passe')).toHaveAttribute(
      'autocomplete',
      'new-password',
    );

    await user.type(actuel, 'AncienMotDePasse!');
    await user.type(nouveau, NOUVEAU);
    await user.type(screen.getByLabelText('Confirmation du nouveau mot de passe'), NOUVEAU);
    await user.click(screen.getByRole('button', { name: 'Enregistrer le nouveau mot de passe' }));

    await waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    expect(bodies[0]).toEqual({
      current_password: 'AncienMotDePasse!',
      password: NOUVEAU,
      password_confirmation: NOUVEAU,
    });
    expect(await screen.findByRole('status')).toHaveTextContent('Votre mot de passe est modifié');
  });

  it('session ouverte par lien : l’ancien mot de passe n’est PAS demandé', async () => {
    const { handler, bodies } = recordPost<Record<string, string>>('/auth/password/change', { changed: true });
    const user = await ouvrirMonCompte([getJson('/workspace', WORKSPACE), etat(false), handler]);

    expect(await screen.findByText(/ouverte par un lien de connexion/)).toBeVisible();
    expect(screen.queryByLabelText('Mot de passe actuel')).not.toBeInTheDocument();

    await user.type(screen.getByLabelText('Nouveau mot de passe'), NOUVEAU);
    await user.type(screen.getByLabelText('Confirmation du nouveau mot de passe'), NOUVEAU);
    await user.click(screen.getByRole('button', { name: 'Enregistrer le nouveau mot de passe' }));

    await waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    expect(bodies[0]).toEqual({ password: NOUVEAU, password_confirmation: NOUVEAU });
  });

  it('ancien mot de passe incorrect : le dit clairement', async () => {
    const user = await ouvrirMonCompte([
      getJson('/workspace', WORKSPACE),
      etat(true),
      postStatus('/auth/password/change', 422, {
        error: 'mot_de_passe_actuel_incorrect',
        message: 'Le mot de passe actuel est incorrect.',
      }),
    ]);

    await user.type(await screen.findByLabelText('Mot de passe actuel'), 'faux');
    await user.type(screen.getByLabelText('Nouveau mot de passe'), NOUVEAU);
    await user.type(screen.getByLabelText('Confirmation du nouveau mot de passe'), NOUVEAU);
    await user.click(screen.getByRole('button', { name: 'Enregistrer le nouveau mot de passe' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Le mot de passe actuel est incorrect.');
  });
});

describe('Connexion — aide « ancien mot de passe pré-rempli »', () => {
  const AIDE = /Le navigateur a peut-être rempli un ancien mot de passe/;

  async function soumettre(): Promise<void> {
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Adresse e-mail'), 'will@axion-ia.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'faux-mot-de-passe');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));
  }

  it('absente à l’ouverture, affichée APRÈS un refus « identifiants incorrects »', async () => {
    await renderScreen(<LoginPage />, {
      path: '/login',
      outsideLayout: true,
      landingRoutes: ['/', '/2fa'],
      handlers: [postStatus('/auth/login', 422, { message: 'Adresse e-mail ou mot de passe incorrect.' })],
    });
    expect(screen.queryByText(AIDE)).not.toBeInTheDocument();

    await soumettre();

    expect(await screen.findByText(AIDE)).toBeVisible();
  });

  it('TÉMOIN : un compte verrouillé ne montre PAS l’aide du navigateur', async () => {
    await renderScreen(<LoginPage />, {
      path: '/login',
      outsideLayout: true,
      landingRoutes: ['/', '/2fa'],
      handlers: [
        postStatus('/auth/login', 422, {
          message: 'auth.locked',
          errors: { email: ['auth.locked'] },
        }),
      ],
    });

    await soumettre();

    expect(await screen.findByRole('alert')).toHaveTextContent('verrouillé');
    expect(screen.queryByText(AIDE)).not.toBeInTheDocument();
  });
});
