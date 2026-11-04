/** PR-C — fiche d'une personne : historique COMPLET paginé, identifiant opaque, états. */
import { describe, it, expect } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HistoriqueOrigines } from '@/features/origines/HistoriqueOrigines';
import { renderScreen } from '../../helpers/renderScreen';
import { dynamicGet, getJson, getPending, getStatus } from '../../msw/handlers';
import { HISTORIQUE } from './fixtures';

type Hs = NonNullable<Parameters<typeof renderScreen>[1]>['handlers'];
const monter = (handlers: Hs) =>
  renderScreen(<HistoriqueOrigines personneId="2" />, { path: '/p/$personneId', url: '/p/2', handlers });

describe('HistoriqueOrigines', () => {
  it('toutes les origines, la plus récente marquée « actuelle »', async () => {
    await monter([getJson('/crm/personnes/2/origines', HISTORIQUE)]);
    expect(await screen.findByText(/Origine actuelle/)).toBeVisible();
    expect(screen.getAllByText(/Origine passée/)).toHaveLength(1);
    expect(screen.getByText('Non classé')).toBeVisible();
  });

  it('post_ref opaque copiable ; AUCUN lien externe, même si le serveur envoie post_url', async () => {
    const { container } = await monter([getJson('/crm/personnes/2/origines', HISTORIQUE)]);
    expect(await screen.findByText('pr_Xk29aF7Qm1')).toBeVisible();
    expect(screen.getByRole('button', { name: /Copier/ })).toBeVisible();
    expect(container.querySelector('a[href^="http"]')).toBeNull();
    expect(container.textContent).not.toContain('reseau.exemple.invalid');
    expect(screen.getByText(/non renseigné/i)).toBeVisible();
  });

  it('pagination par curseur : « Voir plus ancien » demande la suite', async () => {
    const page = dynamicGet('/crm/personnes/2/origines', (_n, url) => {
      const c = url.searchParams.get('curseur');
      return c === null || c === '0'
        ? { ...HISTORIQUE, meta: { total: 3, par_page: 2, suivant: 2 } }
        : { ...HISTORIQUE, data: [{ ...HISTORIQUE.data[1], date: '2026-09-01T00:00:00Z' }], meta: { total: 3, par_page: 2, suivant: null } };
    });
    await monter([page.handler]);
    await userEvent.click(await screen.findByRole('button', { name: /plus anciennes/i }));
    expect(await screen.findAllByText(/Origine passée/)).toHaveLength(2);
    expect(page.urls.some((u) => new URL(u).searchParams.get('curseur') === '2')).toBe(true);
  });

  it('fiche introuvable (404) : même message, ne révèle rien sur la fonction', async () => {
    await monter([getStatus('/crm/personnes/2/origines', 404)]);
    expect(await screen.findByText('Fiche introuvable')).toBeVisible();
    expect(screen.queryByText(/Accès refusé|espace business|fonction/i)).toBeNull();
  });

  it('accès refusé (403)', async () => {
    await monter([getStatus('/crm/personnes/2/origines', 403)]);
    expect(await screen.findByText('Accès refusé')).toBeVisible();
  });

  it('chargement, vide, erreur', async () => {
    const lent = getPending('/crm/personnes/2/origines', HISTORIQUE);
    await monter([lent.handler]);
    expect(await screen.findByText(/Chargement de l’historique/)).toBeVisible();
    lent.release();
  });

  it('vide', async () => {
    await monter([getJson('/crm/personnes/2/origines', { actif: true, data: [], meta: { total: 0, par_page: 50, suivant: null } })]);
    expect(await screen.findByText(/Aucune origine enregistrée/)).toBeVisible();
  });

  it('erreur', async () => {
    await monter([getStatus('/crm/personnes/2/origines', 500)]);
    const alerte = await screen.findByText(/Impossible de charger/);
    expect(within(alerte.closest('div') as HTMLElement).getByRole('button', { name: /Réessayer/ })).toBeVisible();
  });
});
