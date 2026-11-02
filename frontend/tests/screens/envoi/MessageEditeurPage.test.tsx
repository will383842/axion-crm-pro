/**
 * DM2-ASS — l'éditeur de message : contrôleur de contenu, versions A/B de
 * l'objet, enregistrement d'une nouvelle version, aperçu.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MessageEditeurPage } from '@/features/envoi/messages/MessageEditeurPage';
import { handlers } from '@/demo/handlers/envoi/creation.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

const CHEMIN = '/envoi/campagnes/messages/$messageId';

async function ouvrir(id: string) {
  const view = await renderScreen(<MessageEditeurPage />, {
    path: CHEMIN,
    url: `/envoi/campagnes/messages/${id}`,
    handlers,
    landingRoutes: ['/envoi/campagnes/messages'],
  });
  await screen.findByRole('textbox', { name: 'Texte' });
  return { ...view, user: userEvent.setup() };
}

describe('MessageEditeurPage', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
  });

  it('un message sain affiche des contrôles au vert', async () => {
    await ouvrir('msg-1');
    const region = await screen.findByRole('region', { name: 'Contrôles' });
    expect(await within(region).findByText('Aucun mot interdit')).toBeVisible();
    expect(within(region).getByText(/Bloc d’information/)).toBeVisible();
  });

  it('un mot interdit apparaît dans les contrôles', async () => {
    const { user } = await ouvrir('msg-1');
    await user.type(screen.getByRole('textbox', { name: 'Texte' }), ' Offre gratuite.');
    const region = screen.getByRole('region', { name: 'Contrôles' });
    expect(await within(region).findByText(/Mot interdit : « gratuit »/)).toBeVisible();
  });

  it('l’absence du bloc d’information (art. 14) apparaît dans les contrôles', async () => {
    const { user } = await ouvrir('msg-1');
    await user.click(screen.getByRole('switch', { name: /Bloc d’information/ }));
    const region = screen.getByRole('region', { name: 'Contrôles' });
    expect(await within(region).findByText(/Bloc d’information absent/)).toBeVisible();
  });

  it('« Ajouter une version » crée la version B de l’objet, enregistrée avec le message', async () => {
    const { user } = await ouvrir('msg-3');
    expect(screen.queryByRole('textbox', { name: 'Objet · version B' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Ajouter une version/ }));
    const objetB = await screen.findByRole('textbox', { name: 'Objet · version B' });
    await user.type(objetB, 'Suite à notre échange');

    await user.click(screen.getByRole('button', { name: 'Enregistrer la version' }));
    expect(await screen.findByText(/Version 2 enregistrée/)).toBeVisible();
    await waitFor(() => { expect(demoDb.messagesTypes.trouver('msg-3')?.versions).toHaveLength(2); });
    const message = demoDb.messagesTypes.trouver('msg-3')!;
    expect(message.version_courante).toBe(2);
    expect(message.versions[1]?.objet_b).toBe('Suite à notre échange');
    // La version 1 est gardée : on n'écrase jamais.
    expect(message.versions[0]?.numero).toBe(1);
  });

  it('l’aperçu réel remplace les champs pour le destinataire choisi, et bascule en téléphone', async () => {
    const { user } = await ouvrir('msg-1');
    const apercu = await screen.findByRole('region', { name: 'Aperçu réel' });
    expect(await within(apercu).findByText(/Bonjour Paul/)).toBeVisible();
    await user.click(within(apercu).getByRole('button', { name: 'Téléphone' }));
    expect(within(apercu).getByTestId('apercu-mail')).toHaveAttribute('data-format', 'telephone');
  });
});
