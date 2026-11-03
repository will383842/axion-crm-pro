/**
 * ÉCRAN « code reçu par e-mail » (DM1-COM) — `src/features/auth/CodeEmailPage.tsx`.
 * Six chiffres, collage accepté, message clair si le code est faux, renvoi
 * limité, case « faire confiance à cet appareil 30 jours ».
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { CodeEmailPage } from '@/features/auth/CodeEmailPage';
import { renderScreen, type RenderScreenOptions } from '../helpers/renderScreen';
import { apiUrl } from '../msw/handlers';

const OPTIONS: RenderScreenOptions = { path: '/2fa', outsideLayout: true, landingRoutes: ['/'] };

function faux(corpsRecus: unknown[], renvoisAvantRefus = 3) {
  let renvois = 0;
  return [
    http.post(apiUrl('/auth/code-email/verifier'), async ({ request }) => {
      const c = (await request.json()) as { code: string };
      corpsRecus.push(c);
      return c.code === '120127'
        ? HttpResponse.json({ ok: true })
        : HttpResponse.json({ errors: { code: ['Code incorrect.'] } }, { status: 422 });
    }),
    http.post(apiUrl('/auth/code-email/renvoyer'), () => {
      renvois += 1;
      return renvois > renvoisAvantRefus
        ? HttpResponse.json({ message: 'Trop de renvois.' }, { status: 429 })
        : HttpResponse.json({ ok: true, renvois_restants: renvoisAvantRefus - renvois });
    }),
  ];
}

describe('CodeEmailPage', () => {
  it('accepte un code collé avec des espaces et garde six chiffres', async () => {
    const user = userEvent.setup();
    await renderScreen(<CodeEmailPage />, { ...OPTIONS, handlers: faux([]) });
    const champ = screen.getByLabelText('Code à 6 chiffres');
    await user.click(champ);
    await user.paste('12 01 27 99');
    expect(champ).toHaveValue('120127');
    expect(screen.getByRole('button', { name: /Vérifier le code/ })).toBeEnabled();
  });

  it('envoie le code et la case « appareil de confiance 30 jours »', async () => {
    const user = userEvent.setup();
    const recus: unknown[] = [];
    const vue = await renderScreen(<CodeEmailPage />, { ...OPTIONS, handlers: faux(recus) });
    await user.type(screen.getByLabelText('Code à 6 chiffres'), '120127');
    await user.click(screen.getByRole('checkbox', { name: /Faire confiance à cet appareil pendant 30 jours/ }));
    await user.click(screen.getByRole('button', { name: /Vérifier le code/ }));
    await waitFor(() => expect(recus).toEqual([{ code: '120127', appareil_confiance: true }]));
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/'));
  });

  it('explique clairement un mauvais code', async () => {
    const user = userEvent.setup();
    await renderScreen(<CodeEmailPage />, { ...OPTIONS, handlers: faux([]) });
    await user.type(screen.getByLabelText('Code à 6 chiffres'), '000000');
    await user.click(screen.getByRole('button', { name: /Vérifier le code/ }));
    expect(await screen.findByRole('alert')).toHaveTextContent(/Code incorrect/);
  });

  it('limite les renvois', async () => {
    const user = userEvent.setup();
    await renderScreen(<CodeEmailPage />, { ...OPTIONS, handlers: faux([], 1) });
    await user.click(screen.getByRole('button', { name: /Renvoyer le code/ }));
    expect(await screen.findByText(/Un nouveau code vient de partir/)).toBeVisible();
    await user.click(screen.getByRole('button', { name: /Renvoyer le code/ }));
    await waitFor(() => expect(screen.getByRole('button', { name: /Renvoyer le code/ })).toBeDisabled());
    expect(screen.getByText(/Limite de renvois atteinte/)).toBeVisible();
  });
});
