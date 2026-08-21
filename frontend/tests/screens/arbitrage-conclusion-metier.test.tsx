/**
 * D25-003 — `/console/arbitrage` N'AFFICHE PAS SEULEMENT UN ZERO : IL ENONCE
 * UNE CONCLUSION METIER FAUSSE.
 *
 * ═══ LE DEFAUT, ET CE QUI LE DISTINGUE DE D25-001 ═══
 *
 * D25-001 (deja ferme, cf. `tests/screens/etats-erreur.test.tsx`) portait sur la
 * CONFUSION entre echec et vide. Ce constat-ci porte sur la PHRASE elle-meme :
 *
 *     « Rien a arbitrer — Tous les evenements entrants ont trouve leur entreprise. »
 *
 * Ce n'est pas un compteur a zero, c'est une AFFIRMATION SUR L'ETAT DU SYSTEME.
 * B13-001 mesure qu'aucun emetteur du site ne transmet de SIREN, donc que
 * 100 % des leads stationnent sur cet ecran : la phrase est fausse meme quand la
 * file est reellement vide, et elle l'est de la maniere la plus rassurante
 * possible. Un dirigeant qui ouvre l'ecran pendant une panne — ou pendant un
 * chargement — en conclut que le rapprochement automatique fonctionne
 * parfaitement.
 *
 * La garde demandee par le constat, mot pour mot : « un test qui monte l'ecran
 * sous 500 et EXIGE l'absence de la chaine "ont trouve leur entreprise" ».
 * On va plus loin : la conclusion metier ne doit exister DANS AUCUN etat, y
 * compris l'etat vide legitime — l'ecran ne voit que ce que l'ingestion lui a
 * transmis, il ne peut donc rien conclure sur ce qui n'est jamais arrive.
 *
 * ═══ LE ZERO FABRIQUE PENDANT LE CHARGEMENT ═══
 *
 * Second point, non couvert par D25-001 : le sous-titre lisait
 * `list.data?.meta.total ?? 0`. Sous echec, le correctif D25-001 le neutralise
 * — mais PENDANT LE CHARGEMENT, `data` est aussi indefini, et l'ecran annonce
 * « 0 evenement(s) recus sans SIREN » avant d'avoir lu quoi que ce soit. Un
 * zero affiche avant toute lecture est le meme mensonge, en plus fugace.
 *
 * ⚠️ Sous-chaines SANS LETTRE ACCENTUEE : on cherche « leur entreprise », pas
 * « ont trouve leur entreprise » (« trouve » porte un accent dans le produit).
 */
import { describe, expect, it } from 'vitest';
import { screen, waitFor } from '@testing-library/react';

import { ArbitragePage } from '@/features/crm-console/ArbitragePage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, getPending, http, HttpResponse } from '../msw/handlers';

/** La conclusion metier interdite. Sans accent : « trouve » en porte un. */
const CONCLUSION_METIER = 'leur entreprise';

const LIGNE = {
  activity_id: 41,
  kind: 'form.contact',
  title: 'Formulaire contact',
  occurred_at: '2026-08-18T09:12:00Z',
  pending_match: {
    denomination: 'Boulangerie Martin',
    first_name: 'Marie',
    last_name: 'Dupont',
    email: 'marie@example.test',
    postcode: '69003',
    city: 'Lyon',
  },
};

const FILE_VIDE = { data: [], meta: { total: 0, per_page: 50 } };
const FILE_PLEINE = { data: [LIGNE], meta: { total: 3, per_page: 50 } };

function texteEcran(): string {
  return document.body.textContent ?? '';
}

async function monter(handler: ReturnType<typeof getJson>): Promise<void> {
  await renderScreen(<ArbitragePage />, {
    path: '/console/arbitrage',
    consoleFeatures: 'open',
    handlers: [handler],
  });
}

describe('D25-003 — la file d’arbitrage ne conclut rien sur ce qu’elle n’a pas vu', () => {
  it('TEMOIN — avec une ligne, l’ecran l’affiche et ne montre aucun etat vide', async () => {
    await monter(getJson('/crm/arbitrage', FILE_PLEINE));

    await waitFor(() => {
      expect(texteEcran()).toContain('Boulangerie Martin');
    });
    expect(texteEcran()).not.toContain('Rien a arbitrer');
    expect(texteEcran()).not.toContain(CONCLUSION_METIER);
    // Le compteur REEL, lui, doit bien s'afficher : la garde ne demande pas de
    // supprimer le chiffre, elle demande de ne pas en inventer un.
    expect(texteEcran()).toMatch(/3\s+\S*nement/);
  });

  it('200 + file vide : l’ecran dit que la file est vide, sans rien conclure', async () => {
    await monter(getJson('/crm/arbitrage', FILE_VIDE));

    await waitFor(() => {
      expect(texteEcran()).toContain('Rien');
    });
    // Le coeur du constat : « il n'y a rien dans cette file » est un fait ;
    // « tous les evenements ont trouve leur entreprise » est une conclusion sur
    // des evenements que cet ecran n'a jamais vus.
    expect(texteEcran()).not.toContain(CONCLUSION_METIER);
  });

  it('500 : ni la conclusion metier, ni l’etat vide', async () => {
    await renderScreen(<ArbitragePage />, {
      path: '/console/arbitrage',
      consoleFeatures: 'open',
      handlers: [http.get(apiUrl('/crm/arbitrage'), () => new HttpResponse(null, { status: 500 }))],
    });

    await waitFor(() => {
      expect(texteEcran()).toContain('serveur est en panne');
    });
    expect(texteEcran()).not.toContain(CONCLUSION_METIER);
    expect(texteEcran()).not.toContain('Rien');
  });

  it('PENDANT LE CHARGEMENT : aucun chiffre n’est annonce avant d’avoir lu', async () => {
    const { handler, release } = getPending('/crm/arbitrage', FILE_PLEINE);
    await renderScreen(<ArbitragePage />, {
      path: '/console/arbitrage',
      consoleFeatures: 'open',
      handlers: [handler],
    });

    // La requete est EN VOL : le titre est rendu, le sous-titre ne doit porter
    // aucun nombre — surtout pas « 0 evenement(s) ».
    await screen.findByRole('heading', { name: /Rapprochements a arbitrer|Rapprochements à arbitrer/ });
    expect(texteEcran()).not.toMatch(/\d+\s+\S*nement\(s\)/);
    expect(texteEcran()).not.toContain(CONCLUSION_METIER);

    release();

    // TEMOIN de la garde elle-meme : une fois la reponse revenue, le chiffre
    // REEL apparait. Sans lui, un sous-titre vide en permanence passerait.
    await waitFor(() => {
      expect(texteEcran()).toMatch(/3\s+\S*nement\(s\)/);
    });
  });
});
