/**
 * GARDE — 26/10/2026 : la page « Entreprises » restait sur des squelettes et
 * des cartes « calcul en cours » éternelles après un déploiement
 * (`docs/DIAG-LISTE-ENTREPRISES-2026-10-26.md`).
 *
 * Ce que cette garde tient :
 *  1. les cartes disent « indisponible » (et non « calcul en cours ») quand
 *     `/companies/stats` échoue ou ne rend jamais de chiffre ;
 *  2. une réponse `degraded: true` de `/companies` n'est PAS « Aucune
 *     entreprise » (même règle que `NotificationsBell`).
 */
import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';

import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import {
  ATTENTE_MAX_STATS_MS,
  indicateursEntreprises,
  statsIndisponibles,
} from '@/features/companies/indicateurs';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, getStatus } from '../msw/handlers';

const GEO = { regions: [], departments: [] };
const PAGE_VIDE = { data: [], meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 } };
const SANS_CHIFFRE = { total: null, enrichies_pct: null, top_taille: null, top_secteur: null, computed_at: null };

describe('statsIndisponibles / indicateursEntreprises', () => {
  it('« calcul en cours » tant que le serveur travaille, « indisponible » ensuite', () => {
    expect(indicateursEntreprises(SANS_CHIFFRE).tailleSous).toBe('calcul en cours');
    expect(indicateursEntreprises(SANS_CHIFFRE, true).tailleSous).toMatch(/indisponible/);
    expect(indicateursEntreprises(undefined, true).secteurSous).toMatch(/indisponible/);
    expect(indicateursEntreprises(SANS_CHIFFRE, true).enrichiesSous).not.toMatch(/calcul en cours/);
  });

  it('échec sans réponse antérieure ou calcul trop long : indisponible', () => {
    expect(statsIndisponibles({ stats: undefined, enErreur: true, attenteMs: 0 })).toBe(true);
    expect(statsIndisponibles({ stats: undefined, enErreur: false, attenteMs: 0 })).toBe(false);
    expect(statsIndisponibles({ stats: SANS_CHIFFRE, enErreur: false, attenteMs: 1000 })).toBe(false);
    expect(statsIndisponibles({ stats: SANS_CHIFFRE, enErreur: false, attenteMs: ATTENTE_MAX_STATS_MS })).toBe(true);
    const calcule = { ...SANS_CHIFFRE, enrichies_pct: 10, computed_at: '2026-10-26T10:00:00Z' };
    expect(statsIndisponibles({ stats: calcule, enErreur: true, attenteMs: ATTENTE_MAX_STATS_MS })).toBe(false);
  });
});

describe('écran Entreprises en panne', () => {
  it('stats en erreur : les cartes disent « indisponible », jamais « calcul en cours »', async () => {
    await renderScreen(<CompaniesListPage />, {
      path: '/companies',
      url: '/companies',
      handlers: [getJson('/companies', PAGE_VIDE), getJson('/referentiels/geo', GEO), getStatus('/companies/stats', 500)],
    });
    expect((await screen.findAllByText(/indisponible pour le moment/)).length).toBeGreaterThanOrEqual(1);
    expect(screen.queryByText(/calcul en cours/)).toBeNull();
  });

  it('liste « dégradée » : message de panne, pas « Aucune entreprise »', async () => {
    await renderScreen(<CompaniesListPage />, {
      path: '/companies',
      url: '/companies',
      handlers: [
        getJson('/companies', { ...PAGE_VIDE, degraded: true }),
        getJson('/referentiels/geo', GEO),
        getJson('/companies/stats', SANS_CHIFFRE),
      ],
    });
    expect(await screen.findByText('Liste momentanément indisponible')).toBeTruthy();
    expect(screen.queryByText('Aucune entreprise')).toBeNull();
  });
});
