import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';
import { http, HttpResponse } from 'msw';
import { SantePage } from '@/features/envoi/pilotage/SantePage';
import { AlertesEnvoiPage } from '@/features/envoi/conformite/AlertesEnvoiPage';
import { LIBELLE_TYPE_ALERTE } from '@/features/envoi/conformite/composants/typesAlerte';
import { handlers as handlersConformite } from '@/demo/handlers/envoi/conformite.handlers';
import { api } from '@/demo/outils';
import { renderScreen } from '../../helpers/renderScreen';

/** Réponse de la VRAIE route `GET /envoi/sante` (forme de `pilotage.yaml`) : espace où rien n'est encore mesuré, avec une pause. */
const REPONSE_CONTRAT = {
  feu: 'gris',
  boites: [{ id: 'b1', adresse: 'a@prospection.example', domaine_id: '1', nom_affiche: 'a@prospection.example', etat: 'gris', statut: 'inconnu', quota_jour: 20, envoyes_aujourdhui: 0, taux_refus: null, reponses_7j: 0 }],
  domaines: [{ id: '1', nom: 'prospection.example', etat: 'gris', boites: 1, dmarc: null, dmarc_prochaine_etape: null, listes_noires: 0, ajoute_le: '2026-11-01T00:00:00Z', chauffe: null }],
  serveurs: [{ id: '1', nom: 'src', etat: 'gris', adresses_ip: [], version_configuration: null }],
  test_du_matin: { le: null, temoins_ok: 0, temoins_total: 6, etat: 'gris' },
  alertes: 1,
  verifie_le: null,
  volume_jour: { possibles: 20, envoyes: 0 },
  rythme_messageries: [{ messagerie: 'Gmail et Google Workspace', envoyes: 0, par_heure: null, etat: 'freine' }],
  envoyes_14_jours: Array.from({ length: 14 }, (_, i) => ({ jour: `2026-11-${String(i + 1).padStart(2, '0')}`, envoyes: 0 })),
  pauses: [{ motif: 'indesirable_chez_un_temoin', portee: 'fournisseur', fournisseur: 'gmail', fin: '2026-11-13T10:00:00Z' }],
};

describe('SantePage — réponse de la vraie route', () => {
  it('se charge avec la forme du contrat : inconnu affiché comme inconnu, pause visible avec motif, messagerie et fin', async () => {
    await renderScreen(<SantePage />, { path: '/envoi/sante', handlers: [http.get(api('/envoi/sante'), () => HttpResponse.json(REPONSE_CONTRAT))] });
    expect(await screen.findByText('État des envois inconnu')).toBeVisible();
    expect(screen.getByText('Jamais vérifié')).toBeVisible();
    expect(screen.getByText(/test du matin jamais fait/)).toBeVisible();
    expect(screen.getByRole('list', { name: 'Pauses d’envoi actives' })).toHaveTextContent(/Message en indésirables chez un témoin.*gmail.*jusqu’à/);
    expect(screen.getByText('Freinée')).toBeVisible();
    expect(screen.getByText(/DMARC : inconnu/)).toBeVisible();
    expect(screen.getByText('non mesuré')).toBeVisible();
    expect(screen.queryByText('Envois normaux')).toBeNull();
  });
});

describe('Alertes — libellés des types', () => {
  it('chaque type du contrat a un libellé français', () => {
    for (const t of ['temoin_indesirable', 'reponses_en_baisse', 'reports_en_hausse', 'homme_mort', 'sante_silencieuse', 'reponses_non_traitees', 'reputation_mauvaise'] as const) {
      expect(LIBELLE_TYPE_ALERTE[t]).toMatch(/[a-zé]/);
    }
    expect(Object.keys(LIBELLE_TYPE_ALERTE)).toHaveLength(14);
  });

  it('l’écran des alertes affiche le libellé du type quand il est fourni', async () => {
    await renderScreen(<AlertesEnvoiPage />, { path: '/envoi/alertes', handlers: handlersConformite });
    expect(await screen.findByText('Test du matin')).toBeVisible();
  });
});
