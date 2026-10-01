/**
 * ÉCRAN `/login` — `src/features/auth/LoginPage.tsx`.
 *
 * Famille : écran HORS coquille (`outsideLayout`), formulaire, `user-event`.
 * C'est le seul écran de l'application qu'un visiteur non authentifié atteint :
 * s'il ne se monte pas, plus personne n'entre.
 *
 * Deux choses ne se vérifient QU'ICI, et pas dans un test de composant :
 *  - le corps réellement POSTÉ (`{ email, password, remember }`) — le back
 *    Sanctum refuse tout autre nom de champ ;
 *  - l'AIGUILLAGE : `requires_2fa` décide entre `/` et `/2fa`. Avec un routeur
 *    simulé, on n'assurerait que « navigate a été appelé ».
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { LoginPage } from '@/features/auth/LoginPage';
import { renderScreen, type RenderScreenOptions } from '../helpers/renderScreen';
import { API_ORIGIN, apiUrl, http, HttpResponse, postJson, postStatus, recordPost } from '../msw/handlers';

const OPTIONS: RenderScreenOptions = {
  path: '/login',
  outsideLayout: true,
  // Les deux destinations possibles après connexion — sans elles, `navigate`
  // n'aurait nulle part où aller et le parcours ne prouverait rien.
  landingRoutes: ['/', '/2fa'],
};

describe('LoginPage — rendu', () => {
  it('affiche le formulaire complet, avec les libellés FRANÇAIS de fr.json', async () => {
    await renderScreen(<LoginPage />, OPTIONS);

    // Les libellés viennent de l'i18n RÉEL : un test qui chercherait la clé
    // `auth.login.email` passerait alors que la traduction manque.
    expect(screen.getByRole('heading', { name: 'Connexion' })).toBeVisible();
    expect(screen.getByLabelText('Adresse e-mail')).toBeVisible();
    expect(screen.getByLabelText('Mot de passe')).toBeVisible();
    expect(screen.getByRole('button', { name: 'Se connecter' })).toBeEnabled();

    // « Se souvenir de moi » est coché PAR DÉFAUT — c'est ce qui part au back.
    expect(screen.getByRole('checkbox', { name: /Se souvenir de moi/ })).toBeChecked();

    // Le mot de passe est masqué à l'arrivée.
    expect(screen.getByLabelText('Mot de passe')).toHaveAttribute('type', 'password');
  });

  it('propose les deux échappatoires (lien de connexion, mot de passe oublié)', async () => {
    await renderScreen(<LoginPage />, OPTIONS);

    expect(screen.getByRole('link', { name: 'Recevoir un lien de connexion' })).toHaveAttribute(
      'href',
      '/magic-link',
    );
    expect(screen.getByRole('link', { name: /Mot de passe oubli/ })).toHaveAttribute(
      'href',
      '/password-reset',
    );
  });
});

describe('LoginPage — parcours', () => {
  it('saisir puis soumettre POSTe les identifiants et ATTERRIT sur le tableau de bord', async () => {
    const user = userEvent.setup();
    const { handler, bodies } = recordPost<{ email: string; password: string; remember: boolean }>(
      '/auth/login',
      { requires_2fa: false },
    );

    const view = await renderScreen(<LoginPage />, { ...OPTIONS, handlers: [handler] });

    await user.type(screen.getByLabelText('Adresse e-mail'), 'will@axion-ia.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-42');
    await user.click(screen.getByRole('checkbox', { name: /Se souvenir de moi/ }));
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));

    await waitFor(() => {
      expect(bodies).toHaveLength(1);
    });
    // Le corps EXACT — noms de champs compris.
    expect(bodies[0]).toEqual({
      email: 'will@axion-ia.com',
      password: 'motdepasse-42',
      remember: false, // décoché par le clic ci-dessus
    });

    // On a VRAIMENT changé d'écran : le routeur en mémoire l'atteste.
    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/');
    });
    expect(screen.getByTestId('landing')).toHaveAttribute('data-path', '/');
  });

  it('aiguille vers /2fa quand le compte exige un second facteur', async () => {
    const user = userEvent.setup();
    const view = await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [postJson('/auth/login', { requires_2fa: true })],
    });

    await user.type(screen.getByLabelText('Adresse e-mail'), 'will@axion-ia.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'motdepasse-42');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));

    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/2fa');
    });
  });

  it('le bouton œil dévoile puis remasque le mot de passe', async () => {
    const user = userEvent.setup();
    await renderScreen(<LoginPage />, OPTIONS);

    const champ = screen.getByLabelText('Mot de passe');
    await user.type(champ, 'secret');
    expect(champ).toHaveAttribute('type', 'password');

    await user.click(screen.getByRole('button', { name: 'Afficher le mot de passe' }));
    expect(champ).toHaveAttribute('type', 'text');
    // La valeur saisie survit à la bascule — un remontage l'aurait effacée.
    expect(champ).toHaveValue('secret');

    await user.click(screen.getByRole('button', { name: 'Masquer le mot de passe' }));
    expect(champ).toHaveAttribute('type', 'password');
  });

  it('identifiants refusés (422) : on RESTE sur /login et le bouton redevient actif', async () => {
    const user = userEvent.setup();
    const view = await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [postStatus('/auth/login', 422, { message: 'Identifiants invalides.' })],
    });

    await user.type(screen.getByLabelText('Adresse e-mail'), 'will@axion-ia.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'faux');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));

    await waitFor(() => {
      expect(screen.getByRole('button', { name: 'Se connecter' })).toBeEnabled();
    });
    expect(view.router.state.location.pathname).toBe('/login');
    // Et surtout : pas de redirection sauvage de l'intercepteur.
    expect(screen.queryByTestId('landing')).not.toBeInTheDocument();
  });
});

/**
 * Audit UX du 02/10/2026 (P0-6) — LES ÉCHECS DE CONNEXION SE DISTINGUENT.
 *
 * Avant : « Une erreur est survenue. » en toast pour TOUT échec, et parfois la
 * clé brute `auth.failed` du serveur. Un toast apparaissait même à l'ouverture
 * de /login sans geste de l'utilisateur. Ces gardes rougissent si :
 *  - une clé de traduction brute arrive à l'écran ;
 *  - 422 / 429 / 419 / 5xx / réseau coupé donnent le même message ;
 *  - un message d'erreur s'affiche sans soumission ;
 *  - un 419 (jeton CSRF périmé) n'est pas rejoué une fois en silence.
 */
describe('LoginPage — messages d’échec', () => {
  async function soumettre(): Promise<void> {
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Adresse e-mail'), 'will@axion-ia.com');
    await user.type(screen.getByLabelText('Mot de passe'), 'faux-mot-de-passe');
    await user.click(screen.getByRole('button', { name: 'Se connecter' }));
  }

  it('à l’ouverture, AUCUN message d’erreur ni alerte (rien sans geste)', async () => {
    await renderScreen(<LoginPage />, OPTIONS);
    // Laisser passer les effets et requêtes éventuelles du montage.
    await new Promise((resolve) => { setTimeout(resolve, 200); });
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
    expect(document.body.textContent).not.toContain('Une erreur est survenue');
  });

  it('422 avec la CLÉ BRUTE `auth.failed` : message clair, jamais la clé', async () => {
    await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [postStatus('/auth/login', 422, { message: 'auth.failed', errors: { email: ['auth.failed'] } })],
    });
    await soumettre();

    expect(await screen.findByRole('alert')).toHaveTextContent('Adresse e-mail ou mot de passe incorrect.');
    expect(document.body.textContent).not.toContain('auth.failed');
    expect(document.body.textContent).not.toContain('Une erreur est survenue');
  });

  it('422 avec un message français du serveur : il est affiché tel quel', async () => {
    await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [
        postStatus('/auth/login', 422, {
          message: 'Trop d’essais. Réessayez dans 42 secondes.',
          errors: { email: ['Trop d’essais. Réessayez dans 42 secondes.'] },
        }),
      ],
    });
    await soumettre();

    expect(await screen.findByRole('alert')).toHaveTextContent('Réessayez dans 42 secondes.');
  });

  it('429 : « trop d’essais », avec le délai annoncé par Retry-After', async () => {
    await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [
        http.post(apiUrl('/auth/login'), () =>
          HttpResponse.json({ message: 'Too Many Attempts.' }, { status: 429, headers: { 'Retry-After': '37' } }),
        ),
      ],
    });
    await soumettre();

    const alerte = await screen.findByRole('alert');
    expect(alerte).toHaveTextContent('Trop d’essais');
    expect(alerte).toHaveTextContent('37 secondes');
    expect(alerte).not.toHaveTextContent('Too Many Attempts');
  });

  it('panne serveur (500) : le dit, sans parler d’identifiants', async () => {
    await renderScreen(<LoginPage />, { ...OPTIONS, handlers: [postStatus('/auth/login', 500)] });
    await soumettre();

    const alerte = await screen.findByRole('alert');
    expect(alerte).toHaveTextContent('Le serveur a rencontré un problème');
    expect(alerte).not.toHaveTextContent('mot de passe incorrect');
  });

  it('419 : redemande le jeton CSRF et rejoue la connexion UNE fois, en silence', async () => {
    let appels = 0;
    let cookies = 0;
    const view = await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [
        http.get(`${API_ORIGIN}/sanctum/csrf-cookie`, () => {
          cookies += 1;
          return new HttpResponse(null, { status: 204 });
        }),
        http.post(apiUrl('/auth/login'), () => {
          appels += 1;
          return appels === 1
            ? HttpResponse.json({ message: 'CSRF token mismatch.' }, { status: 419 })
            : HttpResponse.json({ requires_2fa: false });
        }),
      ],
    });
    await soumettre();

    await waitFor(() => {
      expect(view.router.state.location.pathname).toBe('/');
    });
    expect(appels).toBe(2);
    // Le cookie a bien été REDEMANDÉ entre les deux essais.
    expect(cookies).toBeGreaterThanOrEqual(1);
    expect(screen.queryByRole('alert')).not.toBeInTheDocument();
  });

  it('419 deux fois de suite : « session expirée, rechargez la page »', async () => {
    await renderScreen(<LoginPage />, {
      ...OPTIONS,
      handlers: [postStatus('/auth/login', 419, { message: 'CSRF token mismatch.' })],
    });
    await soumettre();

    expect(await screen.findByRole('alert')).toHaveTextContent('Votre session a expiré. Rechargez la page');
  });
});
