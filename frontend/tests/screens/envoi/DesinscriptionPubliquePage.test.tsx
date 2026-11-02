/**
 * DM2-CNF — page PUBLIQUE de désinscription (lien d'un e-mail).
 *
 * Un clic suffit ; option « toute mon organisation » ; aucune connexion
 * demandée ; aucun lien vers le site de l'éditeur ; « pourquoi ce message »
 * (information art. 14). Données fictives (dépôt public).
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DesinscriptionPubliquePage } from '@/features/envoi/public/DesinscriptionPubliquePage';
import { handlers, JETON_DEMO } from '@/demo/handlers/envoi/conformite.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

async function ouvrir(jeton: string = JETON_DEMO) {
  return renderScreen(<DesinscriptionPubliquePage />, {
    path: '/desinscription/$jeton',
    url: `/desinscription/${jeton}`,
    outsideLayout: true,
    handlers,
  });
}

describe('Page publique de désinscription (DM2-CNF)', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('un seul clic confirme la désinscription, sans connexion', async () => {
    const user = userEvent.setup();
    await ouvrir();
    expect(await screen.findByRole('heading', { level: 1, name: 'Se désinscrire' })).toBeVisible();
    // Aucune saisie demandée : ni e-mail, ni mot de passe, ni code.
    expect(screen.queryByRole('textbox')).toBeNull();
    expect(document.querySelector('input[type="password"]')).toBeNull();
    const avant = demoDb.opposition.compter();
    await user.click(screen.getByRole('button', { name: 'Confirmer la désinscription' }));
    expect(await screen.findByText(/ne recevra plus rien de notre part/i)).toBeVisible();
    expect(demoDb.opposition.compter()).toBe(avant + 1);
    expect(demoDb.opposition.tous().at(-1)?.portee).toBe('adresse');
  });

  it('l’option « Toute mon organisation » inscrit l’organisation', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await user.click(await screen.findByRole('checkbox', { name: 'Toute mon organisation' }));
    await user.click(screen.getByRole('button', { name: 'Confirmer la désinscription' }));
    await screen.findByText(/ne recevra plus rien de notre part/i);
    expect(demoDb.opposition.tous().at(-1)?.portee).toBe('organisation');
  });

  it('explique « pourquoi ce message », sans suivi des clics', async () => {
    const user = userEvent.setup();
    await ouvrir();
    await user.click(await screen.findByText('Pourquoi ce message ?'));
    const texte = screen.getByTestId('pourquoi-ce-message');
    expect(texte).toHaveTextContent(/adresse professionnelle/i);
    expect(texte).toHaveTextContent(/aucun clic n’est suivi/i);
    expect(texte).not.toHaveTextContent(/aucun pixel/i);
  });

  it('ne contient aucun lien (en particulier vers le site d’Axion)', async () => {
    await ouvrir();
    await screen.findByRole('button', { name: 'Confirmer la désinscription' });
    const main = screen.getByRole('main');
    expect(within(main).queryAllByRole('link')).toHaveLength(0);
    expect(document.querySelector('a[href*="axion"]')).toBeNull();
  });

  it('un jeton inconnu affiche un message sans rien révéler', async () => {
    await ouvrir('jeton-inconnu');
    expect(await screen.findByRole('alert')).toHaveTextContent(/lien n’est plus valable/i);
  });
});
