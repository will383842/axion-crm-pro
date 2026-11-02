import { describe, it, expect, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ChercherEnvoiPage } from '@/features/envoi/pilotage/ChercherEnvoiPage';
import { handlers } from '@/demo/handlers/envoi/pilotage.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

describe('ChercherEnvoiPage — Chercher un envoi', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('l’adresse d’Yves Roche donne « Refusé : adresse inexistante (550 5.1.1) » en français', async () => {
    const user = userEvent.setup();
    const yves = demoDb.contacts.tous().find((c) => c.first_name === 'Yves' && c.last_name === 'Roche')!;
    await renderScreen(<ChercherEnvoiPage />, { path: '/envoi/sante/chercher', handlers });
    await user.type(await screen.findByRole('searchbox', { name: 'Adresse e-mail' }), yves.email);
    await user.click(screen.getByRole('button', { name: 'Chercher' }));
    const tableau = await screen.findByRole('table', { name: 'Messages' });
    expect(within(tableau).getByText('Refusé : adresse inexistante (550 5.1.1)')).toBeVisible();
    expect(await screen.findByText(/Yves Roche/)).toBeVisible();
    expect(screen.getByText(/peut-on lui écrire/i)).toHaveTextContent(/Non/);
  });

  it('une adresse inconnue dit qu’aucun message n’a été trouvé', async () => {
    const user = userEvent.setup();
    await renderScreen(<ChercherEnvoiPage />, { path: '/envoi/sante/chercher', handlers });
    await user.type(await screen.findByRole('searchbox', { name: 'Adresse e-mail' }), 'personne@example.test');
    await user.click(screen.getByRole('button', { name: 'Chercher' }));
    expect(await screen.findByText('Aucun message pour cette adresse')).toBeVisible();
  });
});
