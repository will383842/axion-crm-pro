/**
 * GARDE — un effacement INCOMPLET se voit dans la console (relecture P2,
 * 2026-09-29).
 *
 * La preuve d'un effacement est différée (`VerifierEffacementRgpd`). Quand
 * elle retrouve des coordonnées, la demande reste « En traitement » et porte
 * `metadata.verification = 'incomplete'` et son motif : la page doit le DIRE,
 * pas seulement faire clignoter un statut. Témoin : une demande soldée ne
 * porte aucune mention. Fixtures FICTIVES (dépôt public).
 */
import { describe, it, expect } from 'vitest';
import { screen } from '@testing-library/react';

import { RgpdRequestsPage } from '@/features/rgpd/RgpdRequestsPage';
import { renderScreen } from '../helpers/renderScreen';
import { getJson } from '../msw/handlers';

const MOTIF = 'Coordonnees encore presentes apres effacement : a traiter a la main (voir residus).';

const MOTIF_ECHEC = 'La verification de l effacement a echoue : la relancer, ou verifier a la main (voir journal).';

const DEMANDES = {
  data: [
    {
      id: 1,
      type: 'erasure',
      status: 'processing',
      subject_email: 'zz.incomplet@zz-rgpd.example.invalid',
      requested_at: '2026-09-29T08:00:00+02:00',
      processed_at: '2026-09-29T08:00:05+02:00',
      metadata: { origin: 'site-sync-gdpr', verification: 'incomplete', motif: MOTIF },
    },
    {
      id: 3,
      type: 'erasure',
      status: 'processing',
      subject_email: 'zz.echec@zz-rgpd.example.invalid',
      requested_at: '2026-09-29T07:00:00+02:00',
      processed_at: '2026-09-29T07:00:05+02:00',
      metadata: { origin: 'site-sync-gdpr', verification: 'echec', motif: MOTIF_ECHEC },
    },
    {
      id: 2,
      type: 'erasure',
      status: 'done',
      subject_email: 'zz.solde@zz-rgpd.example.invalid',
      requested_at: '2026-09-29T09:00:00+02:00',
      processed_at: '2026-09-29T09:00:05+02:00',
      metadata: { origin: 'site-sync-gdpr', verification: 'complete' },
    },
  ],
};

describe('RgpdRequestsPage — verdict de la preuve différée', () => {
  it('un effacement incomplet ou en échec affiche son motif ; un effacement soldé, rien', async () => {
    await renderScreen(<RgpdRequestsPage />, {
      path: '/rgpd/requests',
      handlers: [getJson('/rgpd/requests', DEMANDES)],
    });

    await screen.findByText('zz.incomplet@zz-rgpd.example.invalid');
    expect(screen.getAllByText(MOTIF)).toHaveLength(1);
    // Relecture R7 : une vérification en ÉCHEC se voit aussi.
    expect(screen.getAllByText(MOTIF_ECHEC)).toHaveLength(1);
    expect(screen.getByText('zz.solde@zz-rgpd.example.invalid')).toBeInTheDocument();
    expect(screen.queryByText(/Vérification de l’effacement/)).toBeNull();
  });
});
