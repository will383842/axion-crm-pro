import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { definirFeuVertDemo } from '@/demo/handlers/envoi/pilotage.handlers';
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

  // ── F-FV : décision finale ─────────────────────────────────────────────
  describe('décision finale (F-FV)', () => {
    const ORIGINE = 'https://api.localhost/api/v1';
    const moi = (roles: string[]) => http.get(`${ORIGINE}/auth/me`, () => HttpResponse.json({ user: { id: 'u-1', name: 'Démo', email: 'demo@example.test', current_workspace_id: 'ws-1' }, roles }));
    const proprietaire = () => moi(['owner']);
    const montrer = (extra: ReturnType<typeof http.post>[] = [], droits = proprietaire()) =>
      renderScreen(<FeuVertPage />, { path: '/envoi/sante/feu-vert', handlers: [droits, ...extra, ...handlers] });
    const MOTIF = 'Tout a été relu avec le fondateur.';
    const erreurServeur = (statut: number, message: string) =>
      http.post(`${ORIGINE}/envoi/feu-vert/decision`, () => HttpResponse.json({ error: 'x', message }, { status: statut }));

    beforeEach(() => definirFeuVertDemo(null));
    afterEach(() => definirFeuVertDemo(null));

    it('la page dit que l’accord n’active pas l’envoi réel', async () => {
      await montrer();
      expect(await screen.findByText(/n’active pas l’envoi réel/)).toBeVisible();
    });

    it('droits attendus : aucun bouton avant /auth/me, puis masqués pour un administrateur ou un lecteur', async () => {
      for (const roles of [['admin'], ['viewer']]) {
        const vue = await montrer([], moi(roles));
        const { unmount } = vue;
        await screen.findByRole('heading', { name: 'Décision finale' });
        // Droits lus AVANT toute vérification d'absence : sinon « absent » serait vrai pour la mauvaise raison.
        await waitFor(() => expect(vue.queryClient.getQueryState(['auth', 'me'])?.status).toBe('success'));
        await screen.findByText(/Aucune décision/);
        expect(screen.queryByRole('button', { name: 'Accorder le feu vert' })).toBeNull();
        expect(screen.queryByRole('button', { name: 'Retirer le feu vert' })).toBeNull();
        unmount();
      }
    });

    it('propriétaire : « Accorder » désactivé tant qu’un bloquant est rouge, « Retirer » possible', async () => {
      await montrer();
      expect(await screen.findByRole('button', { name: 'Accorder le feu vert' })).toBeDisabled();
      expect(screen.getByRole('button', { name: 'Retirer le feu vert' })).toBeEnabled();
      expect(screen.getByText(/à lever avant d’accorder/)).toBeVisible();
    });

    it('sans bloquant : motif obligatoire, confirmation explicite, If-Match = empreinte lue', async () => {
      definirFeuVertDemo('sans-bloquant');
      const user = userEvent.setup();
      let entete: string | null = null;
      let corps: unknown;
      let lue = '';
      await montrer([
        http.post(`${ORIGINE}/envoi/feu-vert/decision`, async ({ request }) => { entete = request.headers.get('If-Match'); corps = await request.clone().json(); return undefined; }),
      ]);
      const bouton = await screen.findByRole('button', { name: 'Accorder le feu vert' });
      await waitFor(() => expect(bouton).toBeEnabled());
      const lecture = (await (await fetch(`${ORIGINE}/envoi/feu-vert`)).json()) as { empreinte_liste: string };
      lue = lecture.empreinte_liste;
      await user.click(bouton);
      const dialogue = await screen.findByRole('dialog');
      expect(within(dialogue).getByText(/n’active pas l’envoi réel/)).toBeVisible();
      const confirmer = within(dialogue).getByRole('button', { name: 'Confirmer l’accord' });
      expect(confirmer).toBeDisabled();
      await user.type(within(dialogue).getByLabelText(/Motif/), 'court');
      expect(confirmer).toBeDisabled();
      await user.clear(within(dialogue).getByLabelText(/Motif/));
      await user.type(within(dialogue).getByLabelText(/Motif/), MOTIF);
      await user.click(confirmer);
      expect(await screen.findByText(MOTIF)).toBeVisible();
      expect(corps).toEqual({ decision: 'accorde', motif: MOTIF });
      expect(entete).toBe(lue);
      expect(lue).not.toBe('');
      expect(screen.getByText(/Accordé/)).toBeVisible();
    });

    it('412 : message en clair, la liste est relue, aucun réessai silencieux', async () => {
      definirFeuVertDemo('sans-bloquant');
      const user = userEvent.setup();
      let appels = 0;
      let lectures = 0;
      await montrer([
        http.post(`${ORIGINE}/envoi/feu-vert/decision`, () => { appels += 1; return HttpResponse.json({ error: 'empreinte_perimee', message: 'La liste a changé depuis votre lecture.' }, { status: 412 }); }),
        http.get(`${ORIGINE}/envoi/feu-vert`, () => { lectures += 1; return undefined; }),
      ]);
      const bouton = await screen.findByRole('button', { name: 'Accorder le feu vert' });
      await waitFor(() => expect(bouton).toBeEnabled());
      await user.click(bouton);
      await user.type(screen.getByLabelText(/Motif/), MOTIF);
      const avant = lectures;
      await user.click(screen.getByRole('button', { name: 'Confirmer l’accord' }));
      expect(await screen.findByRole('alert')).toHaveTextContent('La liste a changé depuis votre lecture.');
      await waitFor(() => expect(lectures).toBeGreaterThan(avant));
      expect(appels).toBe(1);
    });

    it.each([[428, 'Lisez d’abord la liste.'], [409, 'Un point bloquant n’est pas au vert.'], [422, 'Le motif ne doit pas contenir d’adresse e-mail.']])('%i : le message du serveur est affiché', async (statut, message) => {
      definirFeuVertDemo('sans-bloquant');
      const user = userEvent.setup();
      await montrer([erreurServeur(statut, message)]);
      const bouton = await screen.findByRole('button', { name: 'Accorder le feu vert' });
      await waitFor(() => expect(bouton).toBeEnabled());
      await user.click(bouton);
      await user.type(screen.getByLabelText(/Motif/), MOTIF);
      await user.click(screen.getByRole('button', { name: 'Confirmer l’accord' }));
      expect(await screen.findByRole('alert')).toHaveTextContent(message);
    });

    it('« Retirer » : toujours possible, motif requis, pas d’If-Match, motif invalide signalé', async () => {
      const user = userEvent.setup();
      let entete: string | null = 'non lu';
      let corps: unknown;
      await montrer([
        http.post(`${ORIGINE}/envoi/feu-vert/decision`, async ({ request }) => { entete = request.headers.get('If-Match'); corps = await request.clone().json(); return undefined; }),
      ]);
      await user.click(await screen.findByRole('button', { name: 'Retirer le feu vert' }));
      const dialogue = await screen.findByRole('dialog');
      expect(within(dialogue).getByText(/de 10 à 500 caractères/)).toBeVisible();
      const confirmer = within(dialogue).getByRole('button', { name: 'Confirmer le retrait' });
      expect(confirmer).toBeDisabled();
      await user.type(within(dialogue).getByLabelText(/Motif/), 'Arrêt demandé par prudence.');
      await user.click(confirmer);
      expect(await screen.findByText('Arrêt demandé par prudence.')).toBeVisible();
      expect(corps).toEqual({ decision: 'retire', motif: 'Arrêt demandé par prudence.' });
      expect(entete).toBeNull();
      expect(screen.getByText(/Retiré/)).toBeVisible();
    });

    it('« Retirer » refusé 422 : le refus est dit clairement, le stop n’est pas présenté comme acquis', async () => {
      const user = userEvent.setup();
      await montrer([erreurServeur(422, 'Le motif ne doit pas contenir d’adresse e-mail.')]);
      await user.click(await screen.findByRole('button', { name: 'Retirer le feu vert' }));
      await user.type(screen.getByLabelText(/Motif/), 'écrit à jean @ exemple.test ok');
      await user.click(screen.getByRole('button', { name: 'Confirmer le retrait' }));
      const alerte = await screen.findByRole('alert');
      expect(alerte).toHaveTextContent('Le motif ne doit pas contenir d’adresse e-mail.');
      expect(alerte).toHaveTextContent(/retrait n’a pas été enregistré/);
    });

    it('affiche la dernière décision : décision, motif, date — rien d’autre', async () => {
      definirFeuVertDemo('sans-bloquant');
      const user = userEvent.setup();
      await montrer();
      const bouton = await screen.findByRole('button', { name: 'Accorder le feu vert' });
      await waitFor(() => expect(bouton).toBeEnabled());
      await user.click(bouton);
      await user.type(screen.getByLabelText(/Motif/), MOTIF);
      await user.click(screen.getByRole('button', { name: 'Confirmer l’accord' }));
      const bloc = (await screen.findByRole('heading', { name: 'Décision finale' })).closest('section') as HTMLElement;
      await within(bloc).findByText(MOTIF);
      expect(bloc.textContent).toMatch(/Accordé.*le \d{1,2}/);
      expect(bloc.textContent).not.toContain('@');
    });
  });
});
