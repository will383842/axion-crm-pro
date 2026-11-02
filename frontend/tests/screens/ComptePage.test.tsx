/**
 * ÉCRAN « Mon compte et sécurité » (DM1-COM) — `src/features/settings/ComptePage.tsx`.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { ComptePage } from '@/features/settings/ComptePage';
import { handlers } from '@/demo/handlers/socle/ecrans-communs.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../helpers/renderScreen';
import { ME_FIXTURE, apiUrl } from '../msw/handlers';

/** Le serveur annonce la connexion par code e-mail (lots S3 et S12 ; la démo aussi). */
const meCodeEmail = http.get(apiUrl('/auth/me'), () =>
  HttpResponse.json({ user: { ...ME_FIXTURE.user, two_factor_method: 'email' } }),
);

describe('ComptePage', () => {
  it('liste les appareils de confiance et les raccourcis clavier', async () => {
    demoDb.reinitialiser();
    await renderScreen(<ComptePage />, { path: '/compte', handlers: [meCodeEmail, ...handlers] });
    expect(await screen.findByRole('heading', { name: 'Mon compte et sécurité' })).toBeVisible();
    expect(await screen.findByText(/Téléphone \(Safari\)/)).toBeVisible();
    expect(screen.getAllByRole('button', { name: /^Retirer/ })).toHaveLength(3);
    expect(screen.getByText('Ctrl K')).toBeVisible();
  });

  it('« Retirer » demande le code, refuse un faux code, puis retire l’appareil', async () => {
    demoDb.reinitialiser();
    const user = userEvent.setup();
    await renderScreen(<ComptePage />, { path: '/compte', handlers: [meCodeEmail, ...handlers] });
    await user.click(await screen.findByRole('button', { name: /Retirer Téléphone/ }));
    const dialogue = await screen.findByRole('dialog');
    const champ = within(dialogue).getByLabelText('Code à 6 chiffres');
    await user.type(champ, '000000');
    await user.click(within(dialogue).getByRole('button', { name: /Confirmer le retrait/ }));
    expect(await within(dialogue).findByRole('alert')).toHaveTextContent(/Code incorrect/);
    await user.clear(champ);
    await user.type(champ, '120127');
    await user.click(within(dialogue).getByRole('button', { name: /Confirmer le retrait/ }));
    await waitFor(() => expect(screen.queryByText(/Téléphone \(Safari\)/)).toBeNull());
  });

  it('sans annonce du serveur (production d’aujourd’hui) : ni appareils ni codes de secours, aucun appel à /compte', async () => {
    demoDb.reinitialiser();
    const appels: string[] = [];
    const espions = [
      http.get(apiUrl('/compte/appareils'), () => { appels.push('appareils'); return HttpResponse.json({ data: [] }); }),
      http.get(apiUrl('/compte/codes-secours'), () => { appels.push('codes'); return HttpResponse.json({ restants: 0 }); }),
    ];
    await renderScreen(<ComptePage />, { path: '/compte', handlers: [...espions, ...handlers] });
    expect(await screen.findByRole('heading', { name: 'Mon compte et sécurité' })).toBeVisible();
    expect(await screen.findByText('Raccourcis clavier')).toBeVisible();
    await waitFor(() => expect(screen.getByText('Ctrl K')).toBeVisible());
    expect(screen.queryByText('Appareils de confiance')).toBeNull();
    expect(screen.queryByText('Codes de secours')).toBeNull();
    expect(appels).toEqual([]);
  });
});
