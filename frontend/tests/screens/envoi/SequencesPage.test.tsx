/**
 * F-SEQ — liste et fiche des séquences d'Envoi : compteurs par étape, « Mettre en pause »
 * (avec confirmation), « Reprendre » refusé avec la raison en clair, boutons masqués selon
 * la permission (pause : users.manage ; reprise : workspaces.manage). Aucune personne affichée.
 */
import { afterEach, beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import type { QueryClient } from '@tanstack/react-query';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';
import { SequencesPage } from '@/features/envoi/sequences/SequencesPage';
import { SequencePage } from '@/features/envoi/sequences/SequencePage';
import { handlers } from '@/demo/handlers/envoi/sequences.handlers';
import { demoDb } from '@/demo/base';
import { definirEtatForce } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

const ORIGINE = 'https://api.localhost/api/v1';
const moi = (roles: string[]) => http.get(`${ORIGINE}/auth/me`, () => HttpResponse.json({ user: { id: 'u-1', name: 'Démo', email: 'demo@example.test', current_workspace_id: 'ws-1' }, roles }));

const proprietaire = () => moi(['owner']);
const administrateur = () => moi(['admin']);
const lecteur = () => moi(['viewer']);

async function liste(droits = proprietaire(), avant: ReturnType<typeof http.get>[] = []) {
  return renderScreen(<SequencesPage />, { path: '/envoi/sequences', url: '/envoi/sequences', handlers: [droits, ...avant, ...handlers], landingRoutes: ['/envoi/sequences/$sequenceId'] });
}

async function fiche(id: string, droits = proprietaire(), avant: ReturnType<typeof http.get>[] = []) {
  return renderScreen(<SequencePage />, { path: '/envoi/sequences/$sequenceId', url: `/envoi/sequences/${id}`, handlers: [droits, ...avant, ...handlers] });
}

const statutDe = (id: string) => demoDb.zone<{ id: string; statut: string }>('sequences', 'lignes').trouver(id)?.statut;

beforeEach(() => {
  demoDb.reinitialiser();
  definirEtatForce(null);
});
afterEach(() => definirEtatForce(null));

describe('SequencesPage — liste', () => {
  it('liste les séquences avec leur statut et leurs inscriptions', async () => {
    await liste();
    expect(await screen.findByRole('heading', { level: 1, name: 'Séquences' })).toBeInTheDocument();
    const ligne = await screen.findByRole('row', { name: /Présentation en trois messages/ });
    expect(within(ligne).getByText('Active')).toBeInTheDocument();
    expect(within(ligne).getByText('3 étapes')).toBeInTheDocument();
    expect(within(ligne).getByText('120')).toBeInTheDocument();
    expect(within(await screen.findByRole('row', { name: /Relance courte/ })).getByText('En pause')).toBeInTheDocument();
    expect(within(await screen.findByRole('row', { name: /boîtes témoins/ })).getByText('Boîtes témoins seulement')).toBeInTheDocument();
  });

  it('montre un état vide clair', async () => {
    definirEtatForce('vide');
    await liste();
    expect(await screen.findByText('Aucune séquence pour l’instant')).toBeInTheDocument();
  });

  it('montre une erreur claire avec un bouton Réessayer', async () => {
    definirEtatForce('erreur');
    await liste();
    expect(await screen.findByRole('button', { name: /Réessayer/ })).toBeInTheDocument();
  });
});

describe('SequencePage — fiche', () => {
  it('montre les étapes et leurs compteurs, sans corps de message ni personne', async () => {
    await fiche('seq-01');
    expect(await screen.findByRole('heading', { level: 1, name: 'Présentation en trois messages (démo)' })).toBeInTheDocument();
    const etapes = await screen.findByRole('table', { name: 'Étapes' });
    const lignes = within(etapes).getAllByRole('row');
    expect(lignes).toHaveLength(4);
    expect(within(lignes[1]!).getByText('Une question sur votre activité')).toBeInTheDocument();
    expect(within(lignes[2]!).getByText('+3 j')).toBeInTheDocument();
    expect(within(lignes[2]!).getByText('96')).toBeInTheDocument();
    expect(screen.getByTestId('inscriptions-en-cours')).toHaveTextContent('80');
    expect(screen.queryByText(/@/)).not.toBeInTheDocument();
  });

  it('une séquence inconnue affiche une page claire', async () => {
    await fiche('seq-zzz');
    expect(await screen.findByText(/Cette séquence n’existe pas/)).toBeInTheDocument();
  });

  it('Mettre en pause : confirmation, puis statut « En pause »', async () => {
    const user = userEvent.setup();
    await fiche('seq-01');
    await user.click(await screen.findByRole('button', { name: 'Mettre en pause' }));
    const dialogue = await screen.findByRole('dialog');
    expect(within(dialogue).getByRole('heading', { name: 'Mettre en pause ?' })).toBeInTheDocument();
    expect(statutDe('seq-01')).toBe('active');
    await user.click(within(dialogue).getByRole('button', { name: 'Mettre en pause' }));
    expect(await screen.findByText('En pause')).toBeInTheDocument();
    expect(await screen.findByRole('button', { name: 'Reprendre' })).toBeInTheDocument();
  });

  it('Annuler la pause ne change rien', async () => {
    const user = userEvent.setup();
    await fiche('seq-01');
    await user.click(await screen.findByRole('button', { name: 'Mettre en pause' }));
    await user.click(await screen.findByRole('button', { name: 'Annuler' }));
    expect(statutDe('seq-01')).toBe('active');
  });

  it('Reprendre une séquence en pause manuelle la remet en route', async () => {
    const user = userEvent.setup();
    await fiche('seq-02');
    await user.click(await screen.findByRole('button', { name: 'Reprendre' }));
    expect(await screen.findByText('Active')).toBeInTheDocument();
  });

  it('Reprendre sous frein : 409, la raison s’affiche en clair et le statut reste', async () => {
    const user = userEvent.setup();
    await fiche('seq-04');
    await user.click(await screen.findByRole('button', { name: 'Reprendre' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Un frein est actif : la reprise est refusée tant qu’il dure.');
    expect(screen.getByText('En pause')).toBeInTheDocument();
    expect(statutDe('seq-04')).toBe('en_pause');
  });

  // Les droits viennent de `['auth','me']` : on attend qu'il soit chargé AVANT toute
  // vérification d'absence, sinon « absent » serait vrai pour la mauvaise raison.
  async function droitsCharges(vue: { queryClient: QueryClient }) {
    await waitFor(() => expect(vue.queryClient.getQueryState(['auth', 'me'])?.status).toBe('success'));
  }
  const avecPermissions = (permissions: string[]) => http.get(`${ORIGINE}/auth/me`, () => HttpResponse.json({ user: { id: 'u-1', name: 'Démo', email: 'demo@example.test', current_workspace_id: 'ws-1' }, roles: [], permissions }));

  it('administrateur : Mettre en pause visible, Reprendre masqué', async () => {
    const vue = await fiche('seq-01', administrateur());
    expect(await screen.findByRole('button', { name: 'Mettre en pause' })).toBeInTheDocument();
    await droitsCharges(vue);
    expect(screen.queryByRole('button', { name: 'Reprendre' })).not.toBeInTheDocument();
  });

  it('administrateur sur une séquence en pause : aucun bouton de reprise', async () => {
    const vue = await fiche('seq-02', administrateur());
    await screen.findByRole('heading', { level: 1, name: /Relance courte/ });
    await droitsCharges(vue);
    expect(screen.queryByRole('button', { name: 'Reprendre' })).not.toBeInTheDocument();
  });

  it('lecteur : aucun bouton de geste', async () => {
    const vue = await fiche('seq-01', lecteur());
    await screen.findByRole('heading', { level: 1, name: /Présentation en trois messages/ });
    await droitsCharges(vue);
    expect(screen.queryByRole('button', { name: 'Mettre en pause' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Reprendre' })).not.toBeInTheDocument();
  });

  it('workspaces.manage seul : Reprendre visible sur seq-02, aucun « Mettre en pause » sur seq-01', async () => {
    const vue = await fiche('seq-02', avecPermissions(['workspaces.manage']));
    expect(await screen.findByRole('button', { name: 'Reprendre' })).toBeInTheDocument();
    vue.unmount();
    const vue2 = await fiche('seq-01', avecPermissions(['workspaces.manage']));
    await screen.findByRole('heading', { level: 1, name: /Présentation en trois messages/ });
    await droitsCharges(vue2);
    expect(screen.queryByRole('button', { name: 'Mettre en pause' })).not.toBeInTheDocument();
  });

  it('users.manage seul : « Mettre en pause » visible sur seq-01, aucun Reprendre sur seq-02', async () => {
    const vue = await fiche('seq-01', avecPermissions(['users.manage']));
    expect(await screen.findByRole('button', { name: 'Mettre en pause' })).toBeInTheDocument();
    vue.unmount();
    const vue2 = await fiche('seq-02', avecPermissions(['users.manage']));
    await screen.findByRole('heading', { level: 1, name: /Relance courte/ });
    await droitsCharges(vue2);
    expect(screen.queryByRole('button', { name: 'Reprendre' })).not.toBeInTheDocument();
  });

  it('lecteur : 403 sur la liste → message de droits clair, sans Réessayer', async () => {
    const refus = http.get(`${ORIGINE}/envoi/sequences`, () => HttpResponse.json({ message: 'Forbidden' }, { status: 403 }));
    await liste(lecteur(), [refus]);
    expect(await screen.findByText('Vous n’avez pas les droits sur cette vue')).toBeInTheDocument();
    expect(screen.getByRole('alert')).toHaveTextContent(/demandez le rôle manquant/);
    expect(screen.queryByRole('button', { name: /Réessayer/ })).not.toBeInTheDocument();
  });

  it('lecteur : 403 sur la fiche → message de droits clair, sans Réessayer', async () => {
    const refus = http.get(`${ORIGINE}/envoi/sequences/seq-01`, () => HttpResponse.json({ message: 'Forbidden' }, { status: 403 }));
    await fiche('seq-01', lecteur(), [refus]);
    expect(await screen.findByText('Vous n’avez pas les droits sur cette vue')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Réessayer/ })).not.toBeInTheDocument();
  });

  it('Reprendre refusé en 403 par le serveur : le bandeau de raison et le bouton restent, le message est clair', async () => {
    const user = userEvent.setup();
    const refus = http.post(`${ORIGINE}/envoi/sequences/seq-02/reprise`, () => HttpResponse.json({ message: 'Accès réservé : la reprise exige la permission workspaces.manage.' }, { status: 403 }));
    await fiche('seq-02', proprietaire(), [refus]);
    await user.click(await screen.findByRole('button', { name: 'Reprendre' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Accès réservé');
    expect(screen.getByRole('button', { name: 'Reprendre' })).toBeInTheDocument();
    expect(screen.getByText('En pause')).toBeInTheDocument();
    expect(statutDe('seq-02')).toBe('en_pause');
  });
});
