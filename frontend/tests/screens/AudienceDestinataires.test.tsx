/**
 * GARDE — l'onglet « Destinataires » d'une audience (2026-09-30).
 *
 *  1. l'aperçu chiffré s'affiche : adresses distinctes, organisations,
 *     exclues par motif (libellés en clair) ;
 *  2. le réglage (personnes nommées + fonction « Président ») part tel quel
 *     vers l'API, sous les noms `destinataires_*`.
 *
 * Fixtures FICTIVES (dépôt public).
 */
import { afterEach, describe, it, expect, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { AudienceDetailPage } from '@/features/audiences/AudienceDetailPage';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, http, HttpResponse } from '../msw/handlers';

const notes = vi.hoisted(() => ({ succes: vi.fn(), erreur: vi.fn(), info: vi.fn() }));
vi.mock('sonner', () => ({ toast: { success: notes.succes, error: notes.erreur, info: notes.info } }));

const AUDIENCE = {
  id: 5,
  name: 'ZZ Invités GOFAB',
  description: null,
  criteria: { all: [{ field: 'liste_manuelle', op: 'in', value: [7] }] },
  is_active: true,
  auto_refresh: false,
  member_count: 3,
  refreshed_at: null,
  created_at: '2026-09-30T08:00:00+02:00',
  destinataires: { mode: 'personne_sinon_generique', fonctions: [], personnes_listees: false, avec_adresses_partagees: false },
};

const APERCU = {
  reglage: AUDIENCE.destinataires,
  organisations: 4,
  organisations_avec_destinataire: 3,
  organisations_sans_destinataire: 1,
  destinataires: 3,
  par_type: { generique: 1, nominative: 2 },
  adresses_partagees_entre_organisations: 1,
  doublons_evites: 2,
  exclues: { opposition: 1, non_verifiee: 2, invalide: 0 },
  exclues_total: 3,
  ecartees_par_le_reglage: { generique_remplacee_par_une_personne: 1 },
  ecartees_total: 1,
  lignes: [
    { email: 'a***@zz-maison.example.invalid', type: 'generique', crm_ref: 'organisation:1', fonction: null, nb_organisations: 3,
      organisations: [{ id: 1, nom: 'ZZ Maison 1' }, { id: 2, nom: 'ZZ Maison 2' }, { id: 3, nom: 'ZZ Maison 3' }] },
  ],
};

afterEach(() => {
  vi.restoreAllMocks();
  notes.succes.mockReset();
  notes.erreur.mockReset();
});

describe('onglet Destinataires', () => {
  it('montre l aperçu chiffré et enregistre le réglage sous les noms de l API', async () => {
    const corps: unknown[] = [];
    const put = http.put(apiUrl('/audiences/5'), async ({ request }) => {
      corps.push(await request.json());
      return HttpResponse.json({ data: AUDIENCE });
    });
    await renderScreen(<AudienceDetailPage />, {
      path: '/audiences/$audienceId',
      url: '/audiences/5',
      handlers: [
        getJson('/audiences/5', { data: AUDIENCE }),
        getJson('/audiences/5/members', { data: [] }),
        getJson('/audiences/5/destinataires', { data: APERCU }),
        put,
      ],
      landingRoutes: ['/audiences'],
    });

    await userEvent.click(await screen.findByRole('tab', { name: /Destinataires/ }));
    const apercu = await screen.findByTestId('apercu-destinataires');
    expect(within(apercu).getByText('Adresses distinctes')).toBeInTheDocument();
    expect(within(apercu).getByText('Ne plus écrire (opposition ou suppression) : 1')).toBeInTheDocument();
    expect(within(apercu).getByText('Adresse non vérifiée : 2')).toBeInTheDocument();
    // Un motif à zéro n'est pas affiché.
    expect(within(apercu).queryByText(/Adresse invalide/)).toBeNull();
    expect(within(apercu).getByText(/2 envoi\(s\) en double évité\(s\)/)).toBeInTheDocument();

    await userEvent.click(screen.getByLabelText(/Les personnes nommées seulement/));
    await userEvent.click(screen.getByRole('button', { name: 'Président' }));
    await userEvent.click(screen.getByLabelText(/Seulement les personnes cochées/));
    await userEvent.click(screen.getByRole('button', { name: 'Enregistrer le réglage' }));

    await waitFor(() => expect(corps).toHaveLength(1));
    expect(corps[0]).toEqual({
      destinataires_mode: 'nominatives',
      destinataires_fonctions: ['Président'],
      destinataires_personnes_listees: true,
      destinataires_avec_adresses_partagees: false,
    });
  });
});
