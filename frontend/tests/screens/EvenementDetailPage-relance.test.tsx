/**
 * FICHE ÉVÉNEMENT — enregistrer la démarche (et sa date de relance) remet à
 * jour le compteur « Événements à relancer » de l'accueil (03/10/2026).
 *
 * Le serveur oublie déjà son cache des compteurs ; sans l'invalidation côté
 * écran, l'accueil garderait jusqu'à 60 s l'ancien chiffre en mémoire — même
 * règle que les gestes sur les doublons et l'arbitrage.
 */
import { describe, it, expect } from 'vitest';
import { fireEvent, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { QueryClient } from '@tanstack/react-query';
import { http, HttpResponse } from 'msw';

import { EvenementDetailPage } from '@/features/evenements/EvenementDetailPage';
import { COMPTEURS_A_TRAITER_KEY } from '@/features/a-traiter/compteurs';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson } from '../msw/handlers';

const FICHE = {
  id: 7,
  nom: 'ZZ Salon fictif',
  type: 'salon',
  date_debut: '2026-11-20',
  date_fin: null,
  recurrence: null,
  heure: null,
  ville: 'Lyon',
  departement_code: '69',
  region: '84',
  appel_intervenants: 'inconnu',
  verifie: false,
  participation: 'repere',
  intervention: 'aucune',
  prochaine_relance_at: null,
  lieu: null,
  public_vise: null,
  taille: null,
  prix: null,
  lien_evenement: null,
  lien_inscription: null,
  appel_intervenants_limite: null,
  source_url: null,
  notes: null,
  demarche_note: null,
  organisateurs: [],
  historique: [],
};

describe('EvenementDetailPage — la date de relance et le compteur de l’accueil', () => {
  it('enregistrer la démarche invalide les compteurs « À traiter »', async () => {
    const user = userEvent.setup();
    const client = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: Infinity, staleTime: Infinity } } });
    client.setQueryData(COMPTEURS_A_TRAITER_KEY, { doublons: 1, a_rattacher: 0, relances: 0 });
    let corps: unknown = null;

    await renderScreen(<EvenementDetailPage />, {
      path: '/evenements/$eventId',
      url: '/evenements/7',
      queryClient: client,
      handlers: [
        getJson('/evenements/7', FICHE),
        http.patch(apiUrl('/evenements/7/demarche'), async ({ request }) => {
          corps = await request.json();
          return HttpResponse.json({ ...FICHE, prochaine_relance_at: '2026-10-01T00:00:00+02:00' });
        }),
      ],
    });

    expect(client.getQueryState(COMPTEURS_A_TRAITER_KEY)?.isInvalidated).toBe(false);

    const date = await screen.findByLabelText('Prochaine relance');
    fireEvent.change(date, { target: { value: '2026-10-01' } });
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => {
      expect(client.getQueryState(COMPTEURS_A_TRAITER_KEY)?.isInvalidated).toBe(true);
    });
    expect(corps).toMatchObject({ prochaine_relance_at: '2026-10-01' });
  });
});
