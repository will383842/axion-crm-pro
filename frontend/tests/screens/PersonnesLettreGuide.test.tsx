/**
 * ÉCRANS « Personnes (lettre et guide) » — lot L4-C.
 *
 *   - `/console/lettre-et-guide` : un segment choisi à l'écran ARRIVE dans
 *     l'URL de la requête (sinon le filtre serait décoratif) ;
 *   - `/console/lettre-et-guide/$personneId` : le paramètre est extrait de
 *     l'URL par un VRAI routeur, et le rattachement exige un nom de famille
 *     quand le site n'en a pas transmis — on n'en fabrique jamais un depuis
 *     une adresse.
 *
 * Adresses en `@example.invalid` uniquement (dépôt PUBLIC).
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { PersonnesPage } from '@/features/crm-console/PersonnesPage';
import { PersonneDetailPage } from '@/features/crm-console/PersonneDetailPage';
import type { PersonneFiche, PersonneRow, PersonnesCounts } from '@/features/crm-console/types';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet, recordPost } from '../msw/handlers';

function personne(overrides: Partial<PersonneRow> = {}): PersonneRow {
  return {
    id: 12,
    person_key: 'a'.repeat(64),
    email: 'zz.lettre@example.invalid',
    first_name: null,
    last_name: null,
    email_nature: 'pro',
    locale: 'fr',
    premiere_source: 'guide-ia',
    premiere_source_at: '2026-09-24T10:00:00Z',
    derniere_interaction_at: '2026-09-24T10:00:00Z',
    legal_basis: 'legitimate_interest_b2b',
    statut_lettre: null,
    placement: null,
    rattachee: false,
    contact_id: null,
    company_id: null,
    entreprise: null,
    purge_prevue_le: '2029-09-24',
    ...overrides,
  };
}

const COUNTS: PersonnesCounts = {
  total: 1,
  by_statut_lettre: { abonne: 0, desabonne: 0, aucun: 1 },
  by_nature: { pro: 1, perso: 0, inconnue: 0 },
  by_source: { 'guide-ia': 1 },
  rattachees: 0,
  non_rattachees: 1,
};

describe('PersonnesPage — segments', () => {
  it('affiche la personne et envoie le segment choisi dans la requête', async () => {
    const { handler, urls } = recordGet('/crm/personnes', { data: [personne()], meta: { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false } });

    await renderScreen(<PersonnesPage />, {
      path: '/console/lettre-et-guide',
      url: '/console/lettre-et-guide',
      consoleFeatures: 'open',
      handlers: [handler, getJson('/crm/personnes/counts', COUNTS)],
    });

    expect(await screen.findByText('zz.lettre@example.invalid')).toBeVisible();

    await userEvent.selectOptions(screen.getByLabelText('Filtre statut de la lettre'), 'abonne');

    await waitFor(() => {
      const derniere = new URL(urls[urls.length - 1] as string);
      expect(derniere.searchParams.get('statut_lettre')).toBe('abonne');
    });
  });
});

describe('PersonneDetailPage — rattacher', () => {
  function fiche(overrides: Partial<PersonneFiche> = {}): PersonneFiche {
    return {
      personne: personne(),
      abonnement: null,
      entreprise: null,
      taches: [],
      timeline: [
        {
          id: 1,
          kind: 'lead_magnet_requested',
          title: 'Guide IA entreprise téléchargé',
          content: null,
          occurred_at: '2026-09-24T10:00:00Z',
          due_at: null,
          done_at: null,
          external_ref: 'site:event:x',
        },
      ],
      ...overrides,
    };
  }

  it('extrait l’identifiant de l’URL, et exige un nom de famille avant de rattacher', async () => {
    const { handler, urls } = recordGet('/crm/personnes/12', fiche());
    const post = recordPost<{ company_id: number; last_name?: string }>('/crm/personnes/12/rattacher', { contact_created: true });

    await renderScreen(<PersonneDetailPage />, {
      path: '/console/lettre-et-guide/$personneId',
      url: '/console/lettre-et-guide/12',
      consoleFeatures: 'open',
      handlers: [handler, post.handler],
    });

    expect(await screen.findByText('Guide IA entreprise téléchargé')).toBeVisible();
    expect(new URL(urls[0] as string).pathname).toBe('/api/v1/crm/personnes/12');

    const bouton = screen.getByRole('button', { name: 'Rattacher à cette entreprise' });
    await userEvent.type(screen.getByLabelText('Identifiant d’entreprise'), '1842');
    // Sans nom de famille : le bouton reste désactivé.
    expect(bouton).toBeDisabled();

    await userEvent.type(screen.getByLabelText('Nom de famille'), 'ZZ TEST');
    expect(bouton).toBeEnabled();
    await userEvent.click(bouton);

    await waitFor(() => expect(post.bodies).toHaveLength(1));
    expect(post.bodies[0]).toEqual({ company_id: 1842, last_name: 'ZZ TEST' });
  });
});
