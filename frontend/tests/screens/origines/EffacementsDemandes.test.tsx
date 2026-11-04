/**
 * PR-C — « Effacements demandés ». Règle de fer : AUCUN bouton « Supprimer ».
 * Un seul geste humain : « Marquer comme traité (mise à l’écart faite) » (route `process` existante).
 */
import { describe, it, expect } from 'vitest';
import { screen, within, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { EffacementsDemandesPage } from '@/features/rgpd/EffacementsDemandesPage';
import { renderScreen } from '../../helpers/renderScreen';
import { dynamicGet, getJson, getPending, getStatus, recordPost } from '../../msw/handlers';
import { ME } from './fixtures';

const LIGNES = {
  data: [
    { id: 7, type: 'erasure', status: 'pending', subject_email: 'exe•••@•••.invalid', requested_at: '2026-11-04T06:12:00Z', processed_at: null },
    { id: 5, type: 'erasure', status: 'processing', subject_email: 'exe•••@•••.invalid', requested_at: '2026-10-31T11:03:00Z', processed_at: null },
    { id: 3, type: 'erasure', status: 'done', subject_email: 'exe•••@•••.invalid', requested_at: '2026-10-12T16:52:00Z', processed_at: '2026-10-13T08:00:00Z' },
  ],
};

const OWNER = ME(['rgpd.handle', 'rgpd.view', 'contacts.view_pii'], ['owner']);
const VIEWER = ME(['rgpd.view'], ['viewer']);
const AUCUN = ME([], ['viewer']);

const monter = (me: unknown, handlers: unknown[] = [getJson('/rgpd/requests', LIGNES)]) =>
  renderScreen(<EffacementsDemandesPage />, { path: '/rgpd/effacements', handlers: [getJson('/auth/me', me), ...(handlers as never[])] });

describe('EffacementsDemandesPage', () => {
  it('AUCUN bouton ni lien « Supprimer » (propriétaire comme lecteur)', async () => {
    for (const me of [OWNER, VIEWER]) {
      const { unmount, container } = await monter(me);
      await screen.findAllByText(/EFF-0007/);
      expect(screen.queryByRole('button', { name: /supprim|effacer|purger/i })).toBeNull();
      expect(container.textContent).not.toMatch(/Supprimer/);
      unmount();
    }
  });

  it('propriétaire : UN seul libellé d’action, sur les demandes ouvertes seulement', async () => {
    await monter(OWNER);
    await screen.findAllByText(/EFF-0007/);
    const boutons = screen.getAllByRole('button', { name: 'Marquer comme traité (mise à l’écart faite)' });
    expect(boutons).toHaveLength(2); // pending + processing, pas la demande traitée
    boutons.forEach((b) => expect(b).toBeEnabled());
  });

  it('lecteur (viewer/admin) : liste visible, bouton grisé avec la raison rgpd.handle', async () => {
    await monter(VIEWER);
    await screen.findAllByText(/EFF-0007/);
    screen.getAllByRole('button', { name: 'Marquer comme traité (mise à l’écart faite)' }).forEach((b) => expect(b).toBeDisabled());
    expect(screen.getAllByText(/rgpd\.handle/).length).toBeGreaterThan(0);
  });

  it('la plus ancienne en haut', async () => {
    await monter(OWNER);
    await screen.findAllByText(/EFF-0007/);
    const refs = screen.getAllByText(/^EFF-000\d$/).map((e) => e.textContent);
    expect(refs).toEqual(['EFF-0003', 'EFF-0005', 'EFF-0007']);
  });

  it('demande par défaut statut=ouverte', async () => {
    const liste = dynamicGet('/rgpd/requests', () => LIGNES);
    await monter(OWNER, [liste.handler]);
    await screen.findAllByText(/EFF-0007/);
    expect(new URL(liste.urls[0] as string).searchParams.get('statut')).toBe('ouverte');
  });

  it('confirmation puis POST /rgpd/requests/{id}/process, sans note', async () => {
    const post = recordPost('/rgpd/requests/7/process', { request: { ...LIGNES.data[0], status: 'done' }, result: { supprime: 0 } });
    await monter(OWNER, [getJson('/rgpd/requests', LIGNES), post.handler]);
    const ligne = (await screen.findByText('EFF-0007')).closest('tr, [role="row"]') as HTMLElement;
    await userEvent.click(within(ligne).getByRole('button', { name: /Marquer comme traité/ }));
    const dlg = await screen.findByRole('dialog');
    expect(dlg.textContent).toMatch(/Rien n’est supprimé/);
    await userEvent.click(within(dlg).getByRole('button', { name: /Marquer comme traité/ }));
    await waitFor(() => expect(post.bodies).toHaveLength(1));
    expect(post.bodies[0]).toBeNull();
  });

  it('états : chargement', async () => {
    const lent = getPending('/rgpd/requests', LIGNES);
    await monter(OWNER, [lent.handler]);
    expect(await screen.findByText(/Chargement des demandes d’effacement/)).toBeVisible();
    lent.release();
  });

  it('états : vide', async () => {
    await monter(OWNER, [getJson('/rgpd/requests', { data: [] })]);
    expect(await screen.findByText('Aucune demande d’effacement ouverte')).toBeVisible();
  });

  it('états : erreur', async () => {
    await monter(OWNER, [getStatus('/rgpd/requests', 500)]);
    expect(await screen.findByText(/Impossible de charger cet écran/)).toBeVisible();
  });

  it('états : accès refusé (403) et aucun droit', async () => {
    await monter(AUCUN, [getStatus('/rgpd/requests', 403)]);
    expect(await screen.findByText('Accès refusé')).toBeVisible();
  });

  it('un « stop » n’est pas une mise à l’écart d’effacement : libellé distinct', async () => {
    await monter(OWNER, [getJson('/rgpd/requests', { data: [{ ...LIGNES.data[0], type: 'opposition' }] })]);
    await screen.findByText('EFF-0007').catch(() => null);
    expect(screen.queryByText(/mise à l’écart faite/)).toBeNull();
  });
});
