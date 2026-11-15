/**
 * F-LN — import d'une liste de refus : le fichier est lu par le navigateur et envoyé en texte JSON,
 * simulation puis confirmation, compte rendu chiffré sans aucune adresse, fichier trop gros refusé avant envoi.
 * Données fictives (dépôt public).
 */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { http, HttpResponse } from 'msw';
import { act, fireEvent, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { DesinscriptionsPage } from '@/features/envoi/conformite/DesinscriptionsPage';
import { handlers } from '@/demo/handlers/envoi/conformite.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

const ORIGINE = 'https://api.localhost/api/v1';
/** `etat.repondu` passe à vrai quand le faux serveur a renvoyé la réponse de `/auth/me`. */
const moi = (roles: string[], etat: { repondu: boolean } = { repondu: false }) =>
  http.get(`${ORIGINE}/auth/me`, () => {
    etat.repondu = true;
    return HttpResponse.json({ user: { id: 'u-1', name: 'Démo', email: 'demo@example.test', current_workspace_id: 'ws-1' }, roles });
  });

// jsdom n'a pas `Blob.text()` : on le fournit comme un navigateur.
if (typeof Blob.prototype.text !== 'function') {
  Object.defineProperty(Blob.prototype, 'text', {
    configurable: true,
    value(this: Blob): Promise<string> {
      return new Promise((resolve, reject) => {
        const lecteur = new FileReader();
        lecteur.onload = () => resolve(typeof lecteur.result === 'string' ? lecteur.result : '');
        lecteur.onerror = () => reject(new Error('lecture impossible'));
        lecteur.readAsText(this);
      });
    },
  });
}

const CONTENU = 'un@societe-a.example.test\n=piege@societe-b.example.test\ngmail.com\nautre@societe-c.example.test\n';
const fichier = (contenu = CONTENU) => new File([contenu], 'liste.csv', { type: 'text/csv' });

async function ouvrir(roles: string[] = ['owner'], extra: ReturnType<typeof http.post>[] = [], etat?: { repondu: boolean }) {
  return renderScreen(<DesinscriptionsPage />, { path: '/envoi/desinscriptions', handlers: [moi(roles, etat), ...extra, ...handlers] });
}

async function ouvrirFenetre(user: ReturnType<typeof userEvent.setup>) {
  await user.click(await screen.findByRole('button', { name: 'Importer une liste de refus' }));
  return screen.findByRole('dialog');
}

describe('Import des listes de refus (F-LN)', () => {
  beforeEach(() => {
    demoDb.reinitialiser();
    definirEtatForce(null);
  });

  it('le propriétaire seul voit le bouton : l’absence est vérifiée APRÈS la réponse de /auth/me', async () => {
    const etat = { repondu: false };
    await ouvrir(['admin'], [], etat);
    await screen.findByRole('heading', { level: 1, name: 'Désinscriptions' });
    // L'administrateur a `users.manage` mais pas `workspaces.manage` : si le composant demandait le mauvais droit, le bouton apparaîtrait.
    await waitFor(() => expect(etat.repondu).toBe(true));
    await act(async () => {
      await new Promise((resolve) => setTimeout(resolve, 50));
    });
    expect(screen.queryByRole('button', { name: 'Importer une liste de refus' })).toBeNull();
  });

  it('le propriétaire voit le bouton (témoin positif)', async () => {
    await ouvrir(['owner']);
    expect(await screen.findByRole('button', { name: 'Importer une liste de refus' })).toBeVisible();
  });

  it('simulation : le texte part en JSON, sans FormData, et le compte rendu est chiffré sans adresse', async () => {
    const user = userEvent.setup();
    const vus: { type: string | null; corps: unknown }[] = [];
    await ouvrir(['owner'], [
      http.post(`${ORIGINE}/envoi/opposition/import`, async ({ request }) => {
        vus.push({ type: request.headers.get('content-type'), corps: await request.clone().json() });
        return undefined;
      }),
    ]);
    const fenetre = await ouvrirFenetre(user);
    await user.upload(within(fenetre).getByLabelText(/Fichier de la liste/), fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    expect(await within(fenetre).findByText(/2 adresses valides/i)).toBeVisible();
    expect(within(fenetre).getByText(/0 déjà présente/i)).toBeVisible();
    expect(within(fenetre).getByText(/2 refusées/i)).toBeVisible();
    expect(within(fenetre).getByText(/Ligne 2/)).toBeVisible();
    expect(within(fenetre).getByText(/Ligne 3/)).toBeVisible();
    // H3 : aucune adresse du fichier n'est réaffichée.
    expect(fenetre.textContent).not.toMatch(/societe-[abc]\.example\.test|gmail\.com/);
    expect(vus).toHaveLength(1);
    expect(vus[0]!.type).toContain('application/json');
    expect(vus[0]!.corps).toMatchObject({ etape: 'simulation', contenu: CONTENU });
    expect(vus[0]!.corps).not.toHaveProperty('jeton');
    // Rien n'est encore inscrit.
    expect(demoDb.opposition.tous().some((o) => o.valeur.includes('societe-a'))).toBe(false);
  });

  it('confirmation en deuxième étape : inscrit avec le jeton, le registre augmente', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const avant = demoDb.opposition.compter();
    const fenetre = await ouvrirFenetre(user);
    // Étape 1 visible, confirmation absente tant qu'il n'y a pas de simulation.
    expect(within(fenetre).queryByRole('button', { name: 'Confirmer l’import' })).toBeNull();
    await user.upload(within(fenetre).getByLabelText(/Fichier de la liste/), fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    await within(fenetre).findByText(/2 adresses valides/i);
    expect(demoDb.opposition.compter()).toBe(avant);
    await user.click(within(fenetre).getByRole('button', { name: 'Confirmer l’import' }));
    expect(await within(fenetre).findByText(/Import confirmé/i)).toBeVisible();
    expect(demoDb.opposition.compter()).toBe(avant + 2);
    expect(fenetre.textContent).not.toMatch(/societe-[abc]\.example\.test/);
  });

  it('fichier trop gros : refusé avant tout envoi', async () => {
    const user = userEvent.setup();
    let appels = 0;
    await ouvrir(['owner'], [http.post(`${ORIGINE}/envoi/opposition/import`, () => { appels += 1; return undefined; })]);
    const fenetre = await ouvrirFenetre(user);
    const gros = new File(['a'.repeat(1_048_577)], 'enorme.csv', { type: 'text/csv' });
    await user.upload(within(fenetre).getByLabelText(/Fichier de la liste/), gros);
    expect(await within(fenetre).findByRole('alert')).toHaveTextContent(/trop gros/i);
    expect(within(fenetre).getByRole('button', { name: 'Simuler l’import' })).toBeDisabled();
    expect(appels).toBe(0);
  });

  it('un refus du serveur (409) est dit avec son message, sans réessai silencieux', async () => {
    const user = userEvent.setup();
    await ouvrir(['owner'], [
      http.post(`${ORIGINE}/envoi/opposition/import`, async ({ request }) => {
        const c = (await request.clone().json()) as { etape: string };
        if (c.etape === 'confirmation') return HttpResponse.json({ error: 'jeton_invalide', message: 'Relancez la simulation : le jeton est périmé.' }, { status: 409 });
        return undefined;
      }),
    ]);
    const fenetre = await ouvrirFenetre(user);
    await user.upload(within(fenetre).getByLabelText(/Fichier de la liste/), fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    await within(fenetre).findByText(/2 adresses valides/i);
    await user.click(within(fenetre).getByRole('button', { name: 'Confirmer l’import' }));
    expect(await within(fenetre).findByRole('alert')).toHaveTextContent(/jeton est périmé/);
  });

  it('le focus est replacé sur le compte rendu après la simulation puis après la confirmation', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const fenetre = await ouvrirFenetre(user);
    await user.upload(within(fenetre).getByLabelText(/Fichier de la liste/), fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    const compte = await within(fenetre).findByRole('status');
    await waitFor(() => expect(compte).toHaveFocus());
    expect(compte).toHaveAttribute('tabindex', '-1');
    await user.click(within(fenetre).getByRole('button', { name: 'Confirmer l’import' }));
    await within(fenetre).findByText(/Import confirmé/i);
    await waitFor(() => expect(within(fenetre).getByRole('status')).toHaveFocus());
  });

  it('simulation périmée : champ fichier désactivé pendant l’envoi, réponse de l’ancien fichier ignorée', async () => {
    const user = userEvent.setup();
    let liberer: () => void = () => undefined;
    const bloque = new Promise<void>((resolve) => { liberer = resolve; });
    let appels = 0;
    await ouvrir(['owner'], [
      http.post(`${ORIGINE}/envoi/opposition/import`, async () => {
        appels += 1;
        if (appels === 1) {
          await bloque;
          return HttpResponse.json({ etape: 'simulation', valides: 7, deja_presentes: 0, refusees: [], jeton: 'jeton-ancien' });
        }
        return undefined;
      }),
    ]);
    const fenetre = await ouvrirFenetre(user);
    const champ = within(fenetre).getByLabelText(/Fichier de la liste/);
    await user.upload(champ, fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    await waitFor(() => expect(champ).toBeDisabled());
    // Un changement forcé malgré tout (autre fichier) : la réponse de l'ancien fichier ne doit jamais s'afficher.
    fireEvent.change(champ, { target: { files: [fichier('autre@societe-d.example.test\n')] } });
    await act(async () => {
      liberer();
      await new Promise((resolve) => setTimeout(resolve, 50));
    });
    expect(within(fenetre).queryByText(/7 adresses valides/i)).toBeNull();
    expect(within(fenetre).queryByRole('status')).toBeNull();
    expect(within(fenetre).queryByRole('button', { name: 'Confirmer l’import' })).toBeNull();
    expect(champ).toBeEnabled();
    await waitFor(() => expect(within(fenetre).getByRole('button', { name: 'Simuler l’import' })).toBeEnabled());
  });

  it('après un 409, la simulation peut être relancée dans la fenêtre et le même fichier peut être re-choisi', async () => {
    const user = userEvent.setup();
    let confirmations = 0;
    await ouvrir(['owner'], [
      http.post(`${ORIGINE}/envoi/opposition/import`, async ({ request }) => {
        const c = (await request.clone().json()) as { etape: string };
        if (c.etape === 'confirmation' && confirmations++ === 0) return HttpResponse.json({ error: 'jeton_invalide', message: 'Relancez la simulation : le jeton est périmé.' }, { status: 409 });
        return undefined;
      }),
    ]);
    const fenetre = await ouvrirFenetre(user);
    const champ = within(fenetre).getByLabelText<HTMLInputElement>(/Fichier de la liste/);
    await user.upload(champ, fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    await within(fenetre).findByText(/2 adresses valides/i);
    await user.click(within(fenetre).getByRole('button', { name: 'Confirmer l’import' }));
    await within(fenetre).findByText(/jeton est périmé/);
    expect(within(fenetre).queryByRole('button', { name: 'Confirmer l’import' })).toBeNull();
    expect(champ.value).toBe('');
    // Le focus revient sur le champ fichier (réactivé), pas sur le body.
    await waitFor(() => expect(champ).toHaveFocus());
    // Le contenu est vidé : « Simuler » est inactif tant qu'aucun fichier n'est re-choisi.
    expect(within(fenetre).getByRole('button', { name: 'Simuler l’import' })).toBeDisabled();
    const lectures = vi.spyOn(Blob.prototype, 'text');
    // Le même fichier re-choisi déclenche bien onChange (nouvelle lecture), puis la simulation repart.
    await user.upload(champ, fichier());
    await waitFor(() => expect(lectures).toHaveBeenCalledTimes(1));
    lectures.mockRestore();
    await user.click(await within(fenetre).findByRole('button', { name: 'Simuler l’import' }));
    expect(await within(fenetre).findByText(/2 adresses valides/i)).toBeVisible();
    await user.click(within(fenetre).getByRole('button', { name: 'Confirmer l’import' }));
    expect(await within(fenetre).findByText(/Import confirmé/i)).toBeVisible();
  });

  it('une erreur autre que 409 à la confirmation : message d’erreur, simulation et fichier conservés', async () => {
    const user = userEvent.setup();
    await ouvrir(['owner'], [
      http.post(`${ORIGINE}/envoi/opposition/import`, async ({ request }) => {
        const c = (await request.clone().json()) as { etape: string };
        if (c.etape === 'confirmation') return HttpResponse.json({ message: 'Service momentanément indisponible.' }, { status: 500 });
        return undefined;
      }),
    ]);
    const fenetre = await ouvrirFenetre(user);
    const champ = within(fenetre).getByLabelText<HTMLInputElement>(/Fichier de la liste/);
    await user.upload(champ, fichier());
    await user.click(within(fenetre).getByRole('button', { name: 'Simuler l’import' }));
    await within(fenetre).findByText(/2 adresses valides/i);
    await user.click(within(fenetre).getByRole('button', { name: 'Confirmer l’import' }));
    expect(await within(fenetre).findByRole('alert')).toHaveTextContent(/momentanément indisponible/);
    expect(within(fenetre).getByText(/2 adresses valides/i)).toBeVisible();
    expect(within(fenetre).getByRole('button', { name: 'Confirmer l’import' })).toBeEnabled();
    expect(champ.files).toHaveLength(1);
  });

  it('fichier vide : refusé à l’écran avant tout appel', async () => {
    const user = userEvent.setup();
    let appels = 0;
    await ouvrir(['owner'], [http.post(`${ORIGINE}/envoi/opposition/import`, () => { appels += 1; return undefined; })]);
    const fenetre = await ouvrirFenetre(user);
    await user.upload(within(fenetre).getByLabelText(/Fichier de la liste/), new File([''], 'vide.csv', { type: 'text/csv' }));
    expect(await within(fenetre).findByRole('alert')).toHaveTextContent(/fichier est vide/i);
    expect(within(fenetre).getByRole('button', { name: 'Simuler l’import' })).toBeDisabled();
    expect(appels).toBe(0);
  });

  it('aucun mot « ouverture » ni « suivi d’ouvertures »', async () => {
    const user = userEvent.setup();
    await ouvrir();
    const fenetre = await ouvrirFenetre(user);
    expect(fenetre.textContent).not.toMatch(/ouverture|pixel/i);
  });
});
