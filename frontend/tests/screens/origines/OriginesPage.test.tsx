/**
 * PR-C — écran « Origines des contacts » (5 onglets). Maquette validée :
 * `maquettes-crm/origines.html`. Tests ROUGES d'abord.
 */
import { describe, it, expect } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { OriginesPage } from '@/features/origines/OriginesPage';
import { renderScreen } from '../../helpers/renderScreen';
import { getJson, getPending, getStatus } from '../../msw/handlers';
import { APERCU, ME, PAR_MOT, PAR_RESEAU, PERSONNES, TENDANCE, TOUTES } from './fixtures';

const CORPS = [
  getJson('/crm/origines/apercu', APERCU),
  getJson('/crm/origines/par-reseau', PAR_RESEAU),
  getJson('/crm/origines/par-mot', PAR_MOT),
  getJson('/crm/origines/tendance', TENDANCE),
  getJson('/crm/origines/personnes', PERSONNES),
];

async function monter(permissions: string[], extra = CORPS) {
  return renderScreen(<OriginesPage />, { path: '/origines', handlers: [getJson('/auth/me', ME(permissions)), ...extra] });
}

describe('OriginesPage', () => {
  it('montre les 5 onglets du plan, et seulement eux', async () => {
    await monter(TOUTES);
    const onglets = await screen.findAllByRole('tab');
    expect(onglets.map((o) => o.textContent)).toEqual(['Vue d’ensemble', 'Par réseau', 'Par mot', 'Tendance', 'Personnes']);
  });

  it('vue d’ensemble : « Demandes » vient du serveur et dit que la page guide en fait partie', async () => {
    await monter(TOUTES);
    const carte = (await screen.findByText('Demandes enregistrées')).closest('[data-testid="carte-demandes"]') as HTMLElement;
    expect(within(carte).getByText('1 322')).toBeVisible();
    expect(carte.textContent).toMatch(/page guide/);
    expect(screen.getByText('Non classé')).toBeVisible();
  });

  it('par réseau : pas de colonne « 30 derniers jours » (aucune route), « Sans réseau » compté à part', async () => {
    await monter(TOUTES);
    await userEvent.click(await screen.findByRole('tab', { name: 'Par réseau' }));
    expect(await screen.findByText('Sans réseau')).toBeVisible();
    expect(screen.queryByText(/30 derniers jours/i)).toBeNull();
  });

  it('tendance : un tableau lisible accompagne la courbe', async () => {
    await monter(TOUTES);
    await userEvent.click(await screen.findByRole('tab', { name: 'Tendance' }));
    expect(await screen.findByText('04/11')).toBeVisible();
  });

  describe('export CSV', () => {
    it('data.export ET contacts.view_pii : bouton actif, avec « au plus N lignes »', async () => {
      await monter(TOUTES);
      await userEvent.click(await screen.findByRole('tab', { name: 'Personnes' }));
      const bouton = await screen.findByRole('button', { name: /Exporter \(CSV\)/ });
      expect(bouton).toBeEnabled();
      expect(screen.getByText(/au plus 1 322 lignes/)).toBeVisible();
      expect(screen.getByText(/opposés, quarantaine, provenance tiers, non diffusible/i)).toBeVisible();
    });

    it('sans contacts.view_pii : bouton GRISÉ avec la raison', async () => {
      await monter(['data.export']);
      await userEvent.click(await screen.findByRole('tab', { name: 'Personnes' }));
      const bouton = await screen.findByRole('button', { name: /Exporter \(CSV\)/ });
      expect(bouton).toBeDisabled();
      expect(screen.getByText(/contacts\.view_pii/)).toBeVisible();
    });

    it('contacts.view_pii sans data.export : bouton grisé avec la raison', async () => {
      await monter(['contacts.view_pii']);
      await userEvent.click(await screen.findByRole('tab', { name: 'Personnes' }));
      expect(await screen.findByRole('button', { name: /Exporter \(CSV\)/ })).toBeDisabled();
      expect(screen.getByText(/data\.export/)).toBeVisible();
    });
  });

  describe('états', () => {
    it('chargement', async () => {
      const lent = getPending('/crm/origines/apercu', APERCU);
      await monter(TOUTES, [lent.handler, ...CORPS.slice(1)]);
      expect(await screen.findByText(/Chargement des origines des contacts/)).toBeVisible();
      lent.release();
    });

    it('vide', async () => {
      await monter(TOUTES, [getJson('/crm/origines/apercu', { ...APERCU, personnes: 0, demandes: 0, par_famille: [] })]);
      expect(await screen.findByText('Aucune origine enregistrée pour l’instant')).toBeVisible();
    });

    it('erreur serveur avec « Réessayer »', async () => {
      await monter(TOUTES, [getStatus('/crm/origines/apercu', 500)]);
      expect(await screen.findByText(/Impossible de charger cet écran/)).toBeVisible();
      expect(screen.getByRole('button', { name: /Réessayer/ })).toBeVisible();
    });

    it.each([403, 404])('accès refusé (%i) : écran non disponible', async (code) => {
      await monter(TOUTES, [getStatus('/crm/origines/apercu', code)]);
      expect(await screen.findByText('Accès refusé')).toBeVisible();
      expect(screen.queryByText(/espace business/)).toBeNull();
    });
  });

  it('période de plus de 366 jours : message avant tout appel', async () => {
    await monter(TOUTES);
    await userEvent.type(await screen.findByLabelText('Du'), '2025-01-01');
    await userEvent.type(screen.getByLabelText('Au'), '2026-11-04');
    expect(await screen.findByText(/366 jours au maximum/)).toBeVisible();
  });
});
