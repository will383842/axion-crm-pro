import { describe, it, expect, beforeEach } from 'vitest';
import { http, HttpResponse } from 'msw';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ChercherEnvoiPage } from '@/features/envoi/pilotage/ChercherEnvoiPage';
import { handlers } from '@/demo/handlers/envoi/pilotage.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

// Filet modifié : pourquoi — R04 : la route répond `{data: [{recu_le, classe, boite}]}` (contrat `RechercheEnvoi`) ; l'ancien
// écran lisait `evenements`/`personne`/`peut_ecrire` (l'oracle « peut-on lui écrire ? » a été retiré du contrat à dessein).
async function chercher(adresse: string, surcharge: typeof handlers = []) {
  const user = userEvent.setup();
  await renderScreen(<ChercherEnvoiPage />, { path: '/envoi/sante/chercher', handlers: [...surcharge, ...handlers] });
  await user.type(await screen.findByRole('searchbox', { name: 'Adresse e-mail' }), adresse);
  await user.click(screen.getByRole('button', { name: 'Chercher' }));
}

describe('ChercherEnvoiPage — Chercher un envoi', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('une réponse {data: [une ligne]} affiche la ligne en français, sans oracle « peut-on lui écrire »', async () => {
    await chercher('personne@example.test', [http.get('*/envoi/envois/chercher', () => HttpResponse.json({ data: [{ recu_le: '2026-11-09T07:00:00Z', classe: 'rebond_dur', boite: 'boite-1' }] }))]);
    const tableau = await screen.findByRole('table', { name: 'Événements' });
    expect(within(tableau).getByText('boite-1')).toBeVisible();
    expect(within(tableau).getByText(/Refusé/)).toBeVisible();
    expect(screen.queryByText(/peut-on lui écrire/i)).toBeNull();
  });

  it('une réponse {data: []} dit qu’aucun message n’a été trouvé, sans planter', async () => {
    await chercher('personne@example.test', [http.get('*/envoi/envois/chercher', () => HttpResponse.json({ data: [] }))]);
    expect(await screen.findByText('Aucun message pour cette adresse')).toBeVisible();
  });

  it('le faux serveur de démonstration répond au contrat {data: [...]}', async () => {
    const yves = demoDb.contacts.tous().find((c) => c.first_name === 'Yves' && c.last_name === 'Roche')!;
    await chercher(yves.email);
    expect(await screen.findByRole('table', { name: 'Événements' })).toBeVisible();
  });
});
