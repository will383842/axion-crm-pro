/**
 * Lot G3-E — « demander un code par e-mail pour un geste » (`DemandeCode`)
 * et « saisir un code de secours à la connexion » (`CodeEmailPage`).
 */
import { describe, it, expect } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { CodeEmailPage } from '@/features/auth/CodeEmailPage';
import { DemandeCode } from '@/features/settings/securite/DemandeCode';
import { renderScreen, type RenderScreenOptions } from '../helpers/renderScreen';
import { apiUrl } from '../msw/handlers';
import { server } from '../msw/server';

const OPTIONS: RenderScreenOptions = { path: '/2fa', outsideLayout: true, landingRoutes: ['/'] };

function fenetre() {
  return render(
    <DemandeCode titre="Retirer l’appareil ?" description="Confirmez." libelleAction="Retirer" onClose={() => {}} onConfirmer={async () => {}} />,
  );
}

describe('DemandeCode : demande du code par e-mail', () => {
  it('le bouton appelle POST /compte/code et confirme l’envoi', async () => {
    const user = userEvent.setup();
    let appels = 0;
    server.use(
      http.post(apiUrl('/compte/code'), () => {
        appels += 1;
        return HttpResponse.json({ ok: true });
      }),
    );
    fenetre();
    await user.click(screen.getByRole('button', { name: /Envoyer le code par e-mail/ }));
    await waitFor(() => expect(appels).toBe(1));
    expect(await screen.findByRole('status')).toHaveTextContent(/code vient de partir/i);
  });

  it('429 : message clair, aucune panne affichée', async () => {
    const user = userEvent.setup();
    server.use(http.post(apiUrl('/compte/code'), () => HttpResponse.json({ error: 'trop_de_renvois' }, { status: 429 })));
    fenetre();
    await user.click(screen.getByRole('button', { name: /Envoyer le code par e-mail/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/Trop de codes demandés/);
  });
});

describe('CodeEmailPage : code de secours', () => {
  it('le code de secours envoie 10 caractères', async () => {
    const user = userEvent.setup();
    const recus: unknown[] = [];
    server.use(
      http.post(apiUrl('/auth/code-email/verifier'), async ({ request }) => {
        recus.push(await request.json());
        return HttpResponse.json({ ok: true });
      }),
    );
    const vue = await renderScreen(<CodeEmailPage />, OPTIONS);
    await user.click(screen.getByRole('button', { name: /code de secours/i }));
    const champ = screen.getByLabelText('Code de secours à 10 caractères');
    await user.type(champ, 'aB3dE6gH9k-extra');
    expect(champ).toHaveValue('aB3dE6gH9k');
    await user.click(screen.getByRole('button', { name: /Vérifier le code/ }));
    await waitFor(() => expect(recus).toEqual([{ code: 'aB3dE6gH9k', appareil_confiance: false }]));
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/'));
  });

  it('le bouton reste fermé tant que les 10 caractères ne sont pas saisis', async () => {
    const user = userEvent.setup();
    await renderScreen(<CodeEmailPage />, OPTIONS);
    await user.click(screen.getByRole('button', { name: /code de secours/i }));
    await user.type(screen.getByLabelText('Code de secours à 10 caractères'), 'abc');
    expect(screen.getByRole('button', { name: /Vérifier le code/ })).toBeDisabled();
  });

  it('429 : message « trop d’essais »', async () => {
    const user = userEvent.setup();
    server.use(http.post(apiUrl('/auth/code-email/verifier'), () => HttpResponse.json({ message: 'Too Many' }, { status: 429 })));
    await renderScreen(<CodeEmailPage />, OPTIONS);
    await user.click(screen.getByRole('button', { name: /code de secours/i }));
    await user.type(screen.getByLabelText('Code de secours à 10 caractères'), 'aB3dE6gH9k');
    await user.click(screen.getByRole('button', { name: /Vérifier le code/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/Trop d’essais/);
  });
});
