/**
 * Accès par produit sur « Utilisateurs » (DM1-COM).
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { UsersPage } from '@/features/users/UsersPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl } from '../msw/handlers';

const MOI = { id: 'u-moi', name: 'Moi Meme', email: 'moi@example.test' };
const AUTRE = { id: 'u-autre', name: 'Autre Personne', email: 'autre@example.test' };

function base(corps: unknown[]) {
  return [
    http.get(apiUrl('/auth/me'), () => HttpResponse.json({ user: MOI })),
    http.get(apiUrl('/users'), () =>
      HttpResponse.json({
        data: [
          { ...MOI, roles: ['owner'], acces_produits: { crm: true, envoi: true } },
          { ...AUTRE, roles: ['operator'], acces_produits: { crm: true, envoi: true } },
        ],
      }),
    ),
    http.put(apiUrl('/users/u-autre'), async ({ request }) => {
      corps.push(await request.json());
      return HttpResponse.json({ data: { ...AUTRE, roles: ['operator'] } });
    }),
  ];
}

describe('Utilisateurs : accès par produit', () => {
  it('décocher « Envoi » retire l’accès de la personne', async () => {
    const user = userEvent.setup();
    const corps: unknown[] = [];
    await renderScreen(<UsersPage />, { path: '/users', handlers: base(corps) });
    const caseEnvoi = await screen.findByRole('checkbox', { name: 'Accès à Envoi pour Autre Personne' });
    expect(caseEnvoi).toBeChecked();
    expect(screen.getByRole('checkbox', { name: 'Accès à CRM Pro pour Autre Personne' })).toBeChecked();
    await user.click(caseEnvoi);
    await waitFor(() => expect(corps).toEqual([{ acces_produits: { crm: true, envoi: false } }]));
  });

  it('ne propose jamais « Supprimer » et ne laisse pas modifier son propre accès', async () => {
    await renderScreen(<UsersPage />, { path: '/users', handlers: base([]) });
    await screen.findByRole('checkbox', { name: 'Accès à Envoi pour Autre Personne' });
    expect(screen.queryByRole('button', { name: /Supprimer/ })).toBeNull();
    expect(screen.getByRole('checkbox', { name: 'Accès à Envoi pour Moi Meme' })).toBeDisabled();
  });
});
