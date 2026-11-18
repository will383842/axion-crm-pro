/** F3-B2 — écran « Ajouter une boîte » : deux temps, code puis mot de passe ; le secret ne reste nulle part. */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { BoitesPage } from '@/features/envoi/infrastructure/BoitesPage';
import { demoDb } from '@/demo/base';
import { handlers } from '@/demo/handlers/envoi/infrastructure.handlers';
import { renderScreen } from '../../../../../tests/helpers/renderScreen';
import { apiUrl } from '../../../../../tests/msw/handlers';

const SECRET = 'mot-de-passe-fictif-pour-test';
const moi = (permissions: string[]) => http.get(apiUrl('/auth/me'), () => HttpResponse.json({ permissions }));
const PROPRIETAIRE = ['users.manage', 'workspaces.manage'];
const ADMIN = ['users.manage'];

interface Appels { code: unknown[]; creation: unknown[] }

/** Serveur simulé : code « 123456 » seul accepté ; la boîte créée n'apparaît qu'à la création. */
function serveur(opts: { code?: { statut: number; message: string }; creation?: { statut: number; message: string } } = {}) {
  const appels: Appels = { code: [], creation: [] };
  let creee = false;
  const reponses = [
    http.post(apiUrl('/envoi/boites/ajout/code'), async ({ request }) => {
      appels.code.push(await request.json());
      if (opts.code) return HttpResponse.json({ error: 'x', message: opts.code.message }, { status: opts.code.statut });
      return HttpResponse.json({ expire_le: '2027-01-12T10:00:00.000Z' }, { status: 202 });
    }),
    http.post(apiUrl('/envoi/boites'), async ({ request }) => {
      const corps = (await request.json()) as { code: string };
      appels.creation.push(corps);
      if (opts.creation) return HttpResponse.json({ error: 'x', message: opts.creation.message }, { status: opts.creation.statut });
      if (corps.code !== '123456') return HttpResponse.json({ error: 'code_invalide', message: 'Code invalide, expiré, déjà utilisé ou trop d’essais : demandez un nouveau code.' }, { status: 409 });
      creee = true;
      return HttpResponse.json({ id: '22222222-2222-4222-8222-222222222222', etat: 'en_pause', pause_motif: 'suspension_manuelle' }, { status: 201 });
    }),
    http.get(apiUrl('/envoi/boites'), () => HttpResponse.json({ data: creee ? [{
      id: '22222222-2222-4222-8222-222222222222', adresse: 'nouvelle@boites.example.test', domaine_id: null, nom_affiche: 'nouvelle@boites.example.test',
      etat: 'gris', statut: 'suspendue', quota_jour: 20, envoyes_aujourdhui: 0, taux_refus: null, reponses_7j: 0,
    }] : [] })),
  ];
  return { appels, reponses };
}

async function monter(permissions: string[], s = serveur()) {
  await renderScreen(<BoitesPage />, { path: '/envoi/boites', handlers: [moi(permissions), ...s.reponses, ...handlers] });
  await screen.findByRole('heading', { level: 1, name: 'Boîtes d’envoi' });
  return s;
}

async function remplirEtape1(u: ReturnType<typeof userEvent.setup>) {
  await u.click(await screen.findByRole('button', { name: 'Ajouter une boîte' }));
  const d = await screen.findByRole('dialog');
  await u.type(within(d).getByLabelText('Adresse de la boîte'), 'nouvelle@boites.example.test');
  await u.type(within(d).getByLabelText('Serveur de courrier (hôte)'), 'smtp.boites.example.test');
  await u.type(within(d).getByLabelText('Identifiant de connexion'), 'nouvelle@boites.example.test');
  await u.click(within(d).getByRole('button', { name: 'Recevoir un code' }));
  return d;
}

describe('F3-B2 — ajout d’une boîte', () => {
  beforeEach(() => { demoDb.reinitialiser(); });

  it('non-propriétaire : pas de bouton d’ajout', async () => {
    await monter(ADMIN);
    expect(screen.queryByRole('button', { name: 'Ajouter une boîte' })).toBeNull();
    expect(screen.queryByRole('button', { name: /bientôt disponible/ })).toBeNull();
  });

  it('propriétaire : bouton visible ; aucun appel tant qu’aucun code n’est demandé', async () => {
    const s = await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    await u.click(await screen.findByRole('button', { name: 'Ajouter une boîte' }));
    const d = await screen.findByRole('dialog');
    expect(within(d).queryByLabelText('Mot de passe de la boîte')).toBeNull();
    await u.click(within(d).getByRole('button', { name: 'Recevoir un code' }));
    expect(s.appels.code).toHaveLength(0);
    expect(s.appels.creation).toHaveLength(0);
  });

  it('étape 2 : aucun envoi de création sans code à 6 chiffres ni mot de passe', async () => {
    const s = await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    const creer = await within(d).findByRole('button', { name: 'Créer la boîte' });
    expect(creer).toBeDisabled();
    await u.type(within(d).getByLabelText('Code reçu par courriel'), '123');
    await u.type(within(d).getByLabelText('Mot de passe de la boîte'), SECRET);
    expect(creer).toBeDisabled();
    await u.click(creer);
    expect(s.appels.creation).toHaveLength(0);
    expect(s.appels.code).toHaveLength(1);
  });

  it('code faux : message clair, rien créé, mot de passe et code vidés', async () => {
    const s = await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    await u.type(await within(d).findByLabelText('Code reçu par courriel'), '654321');
    await u.type(within(d).getByLabelText('Mot de passe de la boîte'), SECRET);
    await u.click(within(d).getByRole('button', { name: 'Créer la boîte' }));
    expect(await within(d).findByRole('alert')).toHaveTextContent(/Code invalide/);
    expect(within(d).getByLabelText('Mot de passe de la boîte')).toHaveValue('');
    expect(within(d).getByLabelText('Code reçu par courriel')).toHaveValue('');
    expect(screen.queryByText(/Boîte créée en pause/)).toBeNull();
    expect(s.appels.creation).toHaveLength(1);
  });

  it('code juste : boîte créée en pause, message final, mot de passe effacé, jamais affiché ni gardé', async () => {
    const setItem = vi.spyOn(Storage.prototype, 'setItem');
    const consoleSpies = (['log', 'info', 'warn', 'error', 'debug'] as const).map((m) => vi.spyOn(console, m).mockImplementation(() => undefined));
    const s = await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    await u.type(await within(d).findByLabelText('Code reçu par courriel'), '123456');
    await u.type(within(d).getByLabelText('Mot de passe de la boîte'), SECRET);
    await u.click(within(d).getByRole('button', { name: 'Créer la boîte' }));
    expect(await screen.findByText('Boîte créée en pause : réactivez-la quand le domaine est prêt.')).toBeInTheDocument();
    expect(s.appels.creation).toHaveLength(1);
    expect(s.appels.creation[0]).toMatchObject({ secret: SECRET, code: '123456' });
    expect(s.appels.code[0]).not.toHaveProperty('secret');
    expect(screen.queryByLabelText('Mot de passe de la boîte')).toBeNull();
    expect(document.body.innerHTML).not.toContain(SECRET);
    expect(document.querySelectorAll('input[value="' + SECRET + '"]')).toHaveLength(0);
    expect(window.location.href).not.toContain(SECRET);
    expect(JSON.stringify({ ...localStorage })).not.toContain(SECRET);
    expect(JSON.stringify({ ...sessionStorage })).not.toContain(SECRET);
    for (const [, v] of setItem.mock.calls) expect(String(v)).not.toContain(SECRET);
    for (const spy of consoleSpies) for (const appel of spy.mock.calls) expect(JSON.stringify(appel)).not.toContain(SECRET);
    await waitFor(() => { expect(screen.getByText('nouvelle@boites.example.test')).toBeInTheDocument(); });
    vi.restoreAllMocks();
  });

  it('le champ mot de passe est masqué et sans saisie automatique', async () => {
    await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    const champ = await within(d).findByLabelText('Mot de passe de la boîte');
    expect(champ).toHaveAttribute('type', 'password');
    expect(champ).toHaveAttribute('autocomplete', 'new-password');
  });

  it('409, 429 et 503 de la demande de code : messages en clair ; le 503 dit de patienter', async () => {
    const cas: Array<[number, RegExp]> = [
      [409, /déjà/i],
      [429, /patientez/i],
      [503, /patientez.*3 codes par 15 minutes/i],
    ];
    for (const [statut, motif] of cas) {
      const s = serveur({ code: { statut, message: statut === 409 ? 'Cette boîte existe déjà.' : 'technique interne' } });
      const u = userEvent.setup();
      const { unmount } = await renderScreen(<BoitesPage />, { path: '/envoi/boites', handlers: [moi(PROPRIETAIRE), ...s.reponses, ...handlers] });
      const d = await remplirEtape1(u);
      expect(await within(d).findByRole('alert')).toHaveTextContent(motif);
      expect(within(d).queryByLabelText('Mot de passe de la boîte')).toBeNull();
      expect(within(d).getByRole('alert')).not.toHaveTextContent(/technique interne/);
      unmount();
    }
  });

  it('double clic sur « Créer la boîte » : une seule création', async () => {
    const s = await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    await u.type(await within(d).findByLabelText('Code reçu par courriel'), '123456');
    await u.type(within(d).getByLabelText('Mot de passe de la boîte'), SECRET);
    const creer = within(d).getByRole('button', { name: 'Créer la boîte' });
    await Promise.all([u.click(creer), u.click(creer)]);
    await screen.findByText(/Boîte créée en pause/);
    expect(s.appels.creation).toHaveLength(1);
  });
});

describe('UI-BOITE — recevoir un nouveau code (étape 2)', () => {
  beforeEach(() => { demoDb.reinitialiser(); });

  /** Serveur dont la demande de code répond 202 la première fois, puis le statut demandé. */
  const serveurRenvoi = (secondStatut: number) => {
    const s = serveur();
    const demandes: Array<Record<string, unknown>> = [];
    const code = http.post(apiUrl('/envoi/boites/ajout/code'), async ({ request }) => {
      demandes.push((await request.json()) as Record<string, unknown>);
      if (demandes.length >= 2 && secondStatut !== 202) return HttpResponse.json({ error: 'x', message: 'technique interne' }, { status: secondStatut });
      return HttpResponse.json({ expire_le: '2027-01-12T10:00:00.000Z' }, { status: 202 });
    });
    return { s: { appels: s.appels, reponses: [code, ...s.reponses] }, demandes };
  };

  it('le bouton renvoie la même demande, sans mot de passe ni code, et vide les deux champs', async () => {
    const { s, demandes } = serveurRenvoi(202);
    await monter(PROPRIETAIRE, s);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    await u.type(await within(d).findByLabelText('Code reçu par courriel'), '123456');
    await u.type(within(d).getByLabelText('Mot de passe de la boîte'), SECRET);
    await u.click(within(d).getByRole('button', { name: 'Recevoir un nouveau code' }));
    await waitFor(() => { expect(demandes).toHaveLength(2); });
    expect(demandes[1]).toEqual(demandes[0]);
    expect(JSON.stringify(demandes)).not.toContain(SECRET);
    expect(Object.keys(demandes[1]!).sort()).toEqual(['adresse', 'chiffrement', 'hote', 'port', 'utilisateur']);
    await waitFor(() => { expect(within(d).getByLabelText('Mot de passe de la boîte')).toHaveValue(''); });
    expect(within(d).getByLabelText('Code reçu par courriel')).toHaveValue('');
    expect(s.appels.creation).toHaveLength(0);
    expect(await within(d).findByText(/Un nouveau code est parti/)).toBeVisible();
  });

  it('429 : message « Patientez quelques minutes », le formulaire reste à l’étape 2 et le mot de passe est vidé', async () => {
    const { s } = serveurRenvoi(429);
    await monter(PROPRIETAIRE, s);
    const u = userEvent.setup();
    const d = await remplirEtape1(u);
    await u.type(await within(d).findByLabelText('Code reçu par courriel'), '123456');
    await u.type(within(d).getByLabelText('Mot de passe de la boîte'), SECRET);
    await u.click(within(d).getByRole('button', { name: 'Recevoir un nouveau code' }));
    expect(await within(d).findByRole('alert')).toHaveTextContent('Patientez quelques minutes');
    expect(within(d).getByRole('alert')).not.toHaveTextContent(/technique interne/);
    expect(within(d).getByLabelText('Mot de passe de la boîte')).toHaveValue('');
  });

  it('pas de bouton « nouveau code » à l’étape 1', async () => {
    await monter(PROPRIETAIRE);
    const u = userEvent.setup();
    await u.click(await screen.findByRole('button', { name: 'Ajouter une boîte' }));
    const d = await screen.findByRole('dialog');
    expect(within(d).queryByRole('button', { name: 'Recevoir un nouveau code' })).toBeNull();
  });
});

