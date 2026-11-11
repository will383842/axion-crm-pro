import { describe, it, expect, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http } from 'msw';
import { FeuVertPage } from '@/features/envoi/pilotage/FeuVertPage';
import { handlers } from '@/demo/handlers/envoi/pilotage.handlers';
import { demoDb } from '@/demo/base';
import { renderScreen } from '../../helpers/renderScreen';

const monter = () => renderScreen(<FeuVertPage />, { path: '/envoi/sante/feu-vert', handlers });
const ligne = async (libelle: RegExp | string) => (await screen.findByText(libelle)).closest('li') as HTMLElement;

describe('FeuVertPage — écran du feu vert', () => {
  beforeEach(() => demoDb.reinitialiser());

  it('regroupe les points par groupe, avec la nature auto / manuel', async () => {
    await monter();
    for (const nom of ['Points sur les verrous', 'Banc', 'DNS', 'Serveur', 'Témoins', 'Conformité', 'Exploitation']) {
      expect(await screen.findByRole('heading', { name: nom })).toBeVisible();
    }
    const l = await ligne('Domaines : DNS au vert');
    expect(l.textContent).toContain('Automatique');
    expect((await ligne('Sauvegarde vérifiée')).textContent).toContain('Manuel');
  });

  it('rend chaque état : fait, à faire, attend le serveur réel', async () => {
    await monter();
    expect((await ligne('Domaines : DNS au vert')).textContent).toContain('Fait');
    expect((await ligne('Messages reçus sur chaque boîte témoin')).textContent).toContain('Fait');
    expect((await ligne('Banc des boîtes témoins complet')).textContent).toContain('À faire');
    expect((await ligne('Serveur d’envoi réel branché')).textContent).toContain('Attend le serveur réel');
    expect((await ligne('Serveur d’envoi réel branché')).textContent).not.toContain('À faire');
  });

  it('un point auto n’a aucun bouton', async () => {
    await monter();
    for (const t of ['Domaines : DNS au vert', 'Banc des boîtes témoins complet', 'Serveur d’envoi réel branché']) {
      expect(within(await ligne(t)).queryByRole('button')).toBeNull();
    }
  });

  it('affiche la preuve existante : texte, par, le', async () => {
    await monter();
    const l = await ligne('Sauvegarde vérifiée');
    expect(within(l).getByText(/Restauration d’essai réussie\./)).toBeVisible();
    expect(l.textContent).toMatch(/Camille/);
    expect(l.textContent).toMatch(/le \d{1,2}/);
  });

  it('bloc verrous en lecture seule, sans bouton ni case', async () => {
    await monter();
    const bloc = (await screen.findByRole('region', { name: 'Verrous' }));
    expect(within(bloc).getByText(/n’ouvre aucun verrou et ne signe rien/)).toBeVisible();
    expect(bloc.textContent).toContain('4 sur 6');
    expect(bloc.textContent).toMatch(/Envoi réel[^.]*Inactif/);
    expect(bloc.textContent).toMatch(/Mode témoins[^.]*Actif/);
    expect(bloc.textContent).toMatch(/Signature[^.]*présente/);
    expect(within(bloc).queryByRole('button')).toBeNull();
    expect(within(bloc).queryByRole('checkbox')).toBeNull();
    expect(within(bloc).queryByRole('switch')).toBeNull();
  });

  it('« Marquer fait » ouvre la saisie ; inactif sous 10 caractères, compteur, avertissement e-mail', async () => {
    const user = userEvent.setup();
    await monter();
    await user.click(await screen.findByRole('button', { name: /Marquer fait : Boucles de retour/ }));
    const champ = screen.getByLabelText('Preuve (ce que vous avez constaté)');
    expect(screen.getByText(/N’écrivez JAMAIS d’adresse e-mail/)).toBeVisible();
    const valider = screen.getByRole('button', { name: 'Enregistrer la preuve' });
    expect(screen.getByText('0 / 500')).toBeVisible();
    expect(valider).toBeDisabled();
    await user.type(champ, 'neuf cars.');
    expect(screen.getByText('10 / 500')).toBeVisible();
    await user.clear(champ);
    await user.type(champ, 'court');
    expect(valider).toBeDisabled();
    await user.type(champ, ' mais assez');
    expect(valider).toBeEnabled();
  });

  it('envoie le texte saisi et affiche la preuve ensuite', async () => {
    const user = userEvent.setup();
    let corps: unknown;
    await renderScreen(<FeuVertPage />, {
      path: '/envoi/sante/feu-vert',
      handlers: [http.post('*/envoi/feu-vert/:code/fait', async ({ request }) => { corps = await request.clone().json(); return undefined; }), ...handlers],
    });
    await user.click(await screen.findByRole('button', { name: /Marquer fait : Boucles de retour/ }));
    await user.type(screen.getByLabelText('Preuve (ce que vous avez constaté)'), 'Inscription faite chez deux messageries.');
    await user.click(screen.getByRole('button', { name: 'Enregistrer la preuve' }));
    expect(await screen.findByText(/Inscription faite chez deux messageries\./)).toBeVisible();
    expect(corps).toEqual({ preuve: 'Inscription faite chez deux messageries.', fait: true });
  });

  it('affiche une erreur 422 lisible et garde la saisie', async () => {
    const user = userEvent.setup();
    await monter();
    await user.click(await screen.findByRole('button', { name: /Marquer fait : Boucles de retour/ }));
    const champ = screen.getByLabelText('Preuve (ce que vous avez constaté)');
    await user.type(champ, 'écrit à jean @ exemple.test ok');
    await user.click(screen.getByRole('button', { name: 'Enregistrer la preuve' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('adresse e-mail');
    expect(champ).toHaveValue('écrit à jean @ exemple.test ok');
  });

  it('« Défaire » seulement sur un point manuel avec preuve, avec motif obligatoire', async () => {
    const user = userEvent.setup();
    await monter();
    expect(within(await ligne('Boucles de retour des messageries')).queryByRole('button', { name: /Défaire/ })).toBeNull();
    expect(within(await ligne('Domaines : DNS au vert')).queryByRole('button', { name: /Défaire/ })).toBeNull();
    await user.click(within(await ligne('Sauvegarde vérifiée')).getByRole('button', { name: /Défaire/ }));
    const champ = screen.getByLabelText('Motif (pourquoi vous retirez cette preuve)');
    const valider = screen.getByRole('button', { name: 'Confirmer le retrait' });
    expect(valider).toBeDisabled();
    await user.type(champ, 'court');
    expect(valider).toBeDisabled();
    await user.click(screen.getByRole('button', { name: 'Annuler' }));
    expect((await ligne('Sauvegarde vérifiée')).textContent).toContain('Fait');
    await user.click(within(await ligne('Sauvegarde vérifiée')).getByRole('button', { name: /Défaire/ }));
    await user.type(screen.getByLabelText('Motif (pourquoi vous retirez cette preuve)'), 'Restauration à refaire.');
    await user.click(screen.getByRole('button', { name: 'Confirmer le retrait' }));
    expect(await screen.findByRole('button', { name: /Marquer fait : Sauvegarde vérifiée/ })).toBeVisible();
  });

  it('« Défaire » envoie le motif saisi, jamais l’ancienne preuve ni une phrase fixe', async () => {
    const user = userEvent.setup();
    let corps: unknown;
    await renderScreen(<FeuVertPage />, {
      path: '/envoi/sante/feu-vert',
      handlers: [http.post('*/envoi/feu-vert/:code/fait', async ({ request }) => { corps = await request.clone().json(); return undefined; }), ...handlers],
    });
    await user.click(await screen.findByRole('button', { name: /Défaire : Sauvegarde vérifiée/ }));
    await user.type(screen.getByLabelText('Motif (pourquoi vous retirez cette preuve)'), 'Mauvaise sauvegarde relue.');
    await user.click(screen.getByRole('button', { name: 'Confirmer le retrait' }));
    await screen.findByRole('button', { name: /Marquer fait : Sauvegarde vérifiée/ });
    expect(corps).toEqual({ preuve: 'Mauvaise sauvegarde relue.', fait: false });
  });

  it('point manuel prouvé qui attend le serveur réel : preuve visible, pas « fait », « Défaire » et pas « Marquer fait »', async () => {
    await monter();
    const l = await ligne('Essai de retour sur le serveur réel');
    expect(l.textContent).toContain('Preuve déposée, attend le serveur réel');
    expect(l.textContent).not.toMatch(/Fait/);
    expect(within(l).getByText(/Preuve : Essai relu sur le banc\./)).toBeVisible();
    expect(within(l).queryByRole('button', { name: /Marquer fait/ })).toBeNull();
    expect(within(l).getByRole('button', { name: /Défaire/ })).toBeVisible();
  });

  it('point manuel sans preuve : « Marquer fait » et pas « Défaire »', async () => {
    await monter();
    const l = await ligne('Boucles de retour des messageries');
    expect(within(l).getByRole('button', { name: /Marquer fait/ })).toBeVisible();
    expect(within(l).queryByRole('button', { name: /Défaire/ })).toBeNull();
  });

  it('ne garde aucune phrase fixe de motif dans le code', async () => {
    const { readFileSync } = await import('node:fs');
    const src = readFileSync('src/features/envoi/pilotage/FeuVertPage.tsx', 'utf8');
    expect(src).not.toContain('Preuve retirée depuis');
  });

  it('accessibilité clavier : Tab atteint le bouton, Entrée ouvre la saisie, Échap la ferme', async () => {
    const user = userEvent.setup();
    await monter();
    const bouton = await screen.findByRole('button', { name: /Marquer fait : Boucles de retour/ });
    bouton.focus();
    await user.keyboard('{Enter}');
    const champ = screen.getByLabelText('Preuve (ce que vous avez constaté)');
    expect(champ).toHaveFocus();
    await user.keyboard('{Escape}');
    expect(screen.queryByLabelText('Preuve (ce que vous avez constaté)')).toBeNull();
  });

  it('ne garde aucune preuve figée dans le code', async () => {
    const { readFileSync } = await import('node:fs');
    const src = readFileSync('src/features/envoi/pilotage/FeuVertPage.tsx', 'utf8');
    expect(src).not.toContain('constaté depuis l’écran');
  });
});
