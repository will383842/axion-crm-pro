import { describe, it, expect, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { SantePage } from '@/features/envoi/pilotage/SantePage';
import { handlers } from '@/demo/handlers/envoi/pilotage.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

describe('SantePage — Santé des envois', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('affiche le feu global, les cartes et les feux par domaine', async () => {
    await renderScreen(<SantePage />, { path: '/envoi/sante', handlers });
    expect(await screen.findByRole('heading', { level: 1, name: 'Santé des envois' })).toBeVisible();
    expect(await screen.findByText('prospection-a.example')).toBeVisible();
    expect(screen.getByText('Boîtes prêtes')).toBeVisible();
    expect(screen.getAllByText(/Test du matin/i).length).toBeGreaterThan(0);
  });

  it('le domaine ambre mène à la fiche /envoi/domaines/<id>', async () => {
    const user = userEvent.setup();
    const view = await renderScreen(<SantePage />, {
      path: '/envoi/sante',
      handlers,
      landingRoutes: ['/envoi/domaines/$domaineId'],
    });
    const lien = await screen.findByRole('link', { name: /prospection-b\.example/ });
    expect(lien).toHaveAccessibleName(/Attention/);
    await user.click(lien);
    expect(view.router.state.location.pathname).toBe('/envoi/domaines/dom-b');
  });

  it('« Lancer un test » met à jour le test du matin', async () => {
    const user = userEvent.setup();
    await renderScreen(<SantePage />, { path: '/envoi/sante', handlers });
    await screen.findByText('prospection-a.example');
    await user.click(screen.getByRole('button', { name: 'Lancer un test' }));
    expect(await screen.findByText(/Test lancé à/i)).toBeVisible();
  });
});
