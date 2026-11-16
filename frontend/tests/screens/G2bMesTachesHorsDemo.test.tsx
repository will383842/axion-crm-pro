/**
 * LOT G2-B — « Mes tâches » ouvert hors démo (cahier #619, § 9).
 *
 * Hors démo (`VITE_DEMO` vide, donc en production) :
 *  - /taches montre la vraie liste, servie par `/crm/taches` (serveur simulé par MSW) ;
 *  - /affaires garde la page « en attente » ;
 *  - Ctrl K propose « Mes tâches », pas « Affaires » ;
 *  - le menu a une entrée « Mes tâches » ;
 *  - le rappel du matin disparaît au premier 409, sans boucle.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { MesTachesPage } from '@/features/crm/taches/MesTachesPage';
import { AffairesPage } from '@/features/crm/affaires/AffairesPage';
import { affairesDisponibles, tachesDisponibles } from '@/features/crm/taches/disponible';
import { sectionsDeNavigation } from '@/components/layout/Sidebar';
import { ONGLETS_DISPONIBLES } from '@/features/dashboard/OngletsTableauDeBord';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, http, HttpResponse } from '../msw/handlers';

const reponse = {
  aujourdhui: '2027-01-12',
  rappel_matin: false,
  data: [
    { id: '11', titre: 'Rappeler le contact exemple', contexte: 'Société exemple', origine: 'manuelle', genre: 'tache', echeance: '2027-01-12', heure: null, faite: false, faite_le: null },
  ],
};

function serveur(appels: string[], rappel: () => Response = () => HttpResponse.json({ error: 'rappel_indisponible', actif: false, message: 'Le rappel du matin par courriel n’est pas encore disponible.' }, { status: 409 })) {
  return [
    http.get(apiUrl('/crm/taches'), () => {
      appels.push('GET /crm/taches');
      return HttpResponse.json(reponse);
    }),
    http.put(apiUrl('/crm/taches/rappel-matin'), () => {
      appels.push('PUT /crm/taches/rappel-matin');
      return rappel();
    }),
    http.get(apiUrl('/crm/affaires'), () => {
      appels.push('GET /crm/affaires');
      return HttpResponse.json({ data: [] });
    }),
  ];
}

beforeEach(() => {
  vi.stubEnv('VITE_DEMO', '');
});
afterEach(() => {
  vi.unstubAllEnvs();
  vi.resetModules();
});

describe('G2-B — disponibilité hors démo', () => {
  it('tachesDisponibles() est vrai hors démo ; affairesDisponibles() reste faux', () => {
    expect(tachesDisponibles()).toBe(true);
    expect(affairesDisponibles()).toBe(false);
  });

  it('en démo, les deux sont vrais', () => {
    vi.stubEnv('VITE_DEMO', '1');
    expect(tachesDisponibles()).toBe(true);
    expect(affairesDisponibles()).toBe(true);
  });
});

describe('G2-B — /taches et /affaires hors démo', () => {
  it('/taches montre la liste du serveur, pas la page « en attente »', async () => {
    const appels: string[] = [];
    await renderScreen(<MesTachesPage />, { path: '/taches', handlers: serveur(appels) });
    expect(await screen.findByText('Rappeler le contact exemple')).toBeVisible();
    expect(screen.queryByTestId('page-en-attente')).not.toBeInTheDocument();
    expect(appels).toContain('GET /crm/taches');
  });

  it('/affaires garde la page « en attente » et n’appelle pas /crm/affaires', async () => {
    const appels: string[] = [];
    await renderScreen(<AffairesPage />, { path: '/affaires', handlers: serveur(appels) });
    expect(await screen.findByTestId('page-en-attente')).toBeVisible();
    expect(appels).toEqual([]);
  });

  it('la barre d’onglets n’offre pas « Affaires » hors démo, mais offre « Mes tâches »', () => {
    const cibles = ONGLETS_DISPONIBLES().map((o) => o.to);
    expect(cibles).toContain('/taches');
    expect(cibles).not.toContain('/affaires');
  });
});

describe('G2-B — rappel du matin : 409 au premier essai', () => {
  it('masque l’interrupteur, explique, et ne boucle pas', async () => {
    const user = userEvent.setup();
    const appels: string[] = [];
    await renderScreen(<MesTachesPage />, { path: '/taches', handlers: serveur(appels) });
    const bascule = await screen.findByRole('switch', { name: /Recevoir mes tâches à 08:00/ });
    await user.click(bascule);
    expect(await screen.findByText(/rappel du matin par courriel n.est pas encore disponible/i)).toBeVisible();
    expect(screen.queryByRole('switch')).not.toBeInTheDocument();
    // Laisser le temps à une éventuelle boucle de se manifester.
    await new Promise((r) => setTimeout(r, 200));
    expect(appels.filter((a) => a.startsWith('PUT'))).toHaveLength(1);
  });
});

describe('G2-B — Ctrl K', () => {
  it('propose « Mes tâches », pas « Affaires », hors démo', async () => {
    vi.resetModules();
    const { commandes } = await import('@/features/crm/taches/commandes');
    const ids = commandes.map((c) => c.id);
    expect(ids).toContain('crm-mes-taches');
    expect(ids).not.toContain('crm-affaires');
  });
});

describe('G2-B — menu', () => {
  it('une entrée « Mes tâches » mène à /taches', () => {
    const items = sectionsDeNavigation({ console_v2: true, universes: { business: true, vivier: false } }).flatMap((s) => s.items);
    const entree = items.find((i) => i.to === '/taches');
    expect(entree?.label).toBe('Mes tâches');
    expect(items.find((i) => i.to === '/affaires')).toBeUndefined();
  });
});
