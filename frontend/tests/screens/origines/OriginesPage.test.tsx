/**
 * PR-C — écran « Origines des contacts » (5 onglets). Maquette validée :
 * `maquettes-crm/origines.html`. Tests ROUGES d'abord.
 */
import { describe, it, expect } from 'vitest';
import { http, HttpResponse } from 'msw';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { OriginesPage } from '@/features/origines/OriginesPage';
import { renderScreen } from '../../helpers/renderScreen';
import { apiUrl, dynamicGet, getJson, getPending, getStatus } from '../../msw/handlers';
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

  describe('filtres refusés par le serveur : contrôlés AVANT tout appel', () => {
    const avecCompteur = async () => {
      const apercu = dynamicGet('/crm/origines/apercu', () => APERCU);
      await monter(TOUTES, [apercu.handler, ...CORPS.slice(1)]);
      await screen.findByRole('tab', { name: 'Personnes' });
      return apercu;
    };

    it('période de plus de 366 jours entre Du et Au : message, aucune requête', async () => {
      const apercu = await avecCompteur();
      const avant = apercu.urls.length;
      await userEvent.type(screen.getByLabelText('Du'), '2025-01-01');
      await userEvent.type(screen.getByLabelText('Au'), '2026-11-04');
      expect(await screen.findByText(/366 jours au maximum entre/)).toBeVisible();
      expect(apercu.urls.slice(avant).filter((u) => new URL(u).searchParams.has('au'))).toHaveLength(0);
    });

    it('Du seul, plus ancien que 366 jours : message, aucune requête, Filtrage reste affiché', async () => {
      const apercu = await avecCompteur();
      const avant = apercu.urls.length;
      await userEvent.type(screen.getByLabelText('Du'), '2020-01-01');
      expect(await screen.findByText(/Date trop éloignée/)).toBeVisible();
      expect(apercu.urls).toHaveLength(avant);
      expect(screen.getByLabelText('Du')).toBeVisible();
      expect(screen.getByRole('button', { name: 'Réinitialiser' })).toBeVisible();
    });

    it('Du après Au : message, aucune requête', async () => {
      const apercu = await avecCompteur();
      const avant = apercu.urls.length;
      const aujourdhui = new Date();
      const iso = (j: number) => new Date(aujourdhui.getTime() + j * 86_400_000).toISOString().slice(0, 10);
      await userEvent.type(screen.getByLabelText('Au'), iso(-10));
      await userEvent.type(screen.getByLabelText('Du'), iso(-5));
      expect(await screen.findByText(/« Du » doit être avant/)).toBeVisible();
      expect(apercu.urls.slice(avant).filter((u) => new URL(u).searchParams.has('du'))).toHaveLength(0);
    });

    it.each(['clé', 'mot clé'])('Mot « %s » hors du motif du serveur : message, aucune requête', async (mot) => {
      const apercu = await avecCompteur();
      const avant = apercu.urls.length;
      await userEvent.type(screen.getByLabelText('Mot'), mot);
      expect(await screen.findByText(/Mot refusé/)).toBeVisible();
      expect(apercu.urls.slice(avant).filter((u) => new URL(u).searchParams.has('mot') && !/^[a-z0-9][a-z0-9._-]{0,63}$/.test(new URL(u).searchParams.get('mot') ?? ''))).toHaveLength(0);
      expect(screen.getByRole('button', { name: 'Réinitialiser' })).toBeVisible();
    });

    it('réponse 422 : message propre (pas « n’a pas répondu »), Filtrage et Réinitialiser restent, puis Réinitialiser relance', async () => {
      let motEnvoye = false;
      const apercu = http.get(apiUrl('/crm/origines/apercu'), ({ request }) => {
        if (new URL(request.url).searchParams.get('mot') === 'guide') { motEnvoye = true; return HttpResponse.json({ message: 'refusé' }, { status: 422 }); }
        return HttpResponse.json(APERCU);
      });
      await monter(TOUTES, [apercu, ...CORPS.slice(1)]);
      await screen.findByRole('tab', { name: 'Personnes' });
      await userEvent.type(screen.getByLabelText('Mot'), 'guide');
      expect(await screen.findByText(/Filtre refusé : vérifiez les dates et le mot/)).toBeVisible();
      expect(motEnvoye).toBe(true);
      expect(screen.queryByText(/n’a pas répondu|Impossible de charger/)).toBeNull();
      expect(screen.getByLabelText('Mot')).toBeVisible();
      await userEvent.click(screen.getByRole('button', { name: 'Réinitialiser' }));
      expect(await screen.findByRole('tab', { name: 'Personnes' })).toBeVisible();
      expect(screen.queryByText(/Filtre refusé/)).toBeNull();
    });
  });
});
