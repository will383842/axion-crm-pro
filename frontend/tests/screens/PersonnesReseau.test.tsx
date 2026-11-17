/**
 * PO-LE — liste des personnes : colonne et filtre « Réseau » (écran).
 *
 * La colonne et le filtre n'existent QUE si `GET /crm/origines/par-reseau` répond `actif` : 404 (ou `actif: false`)
 * ⇒ ni colonne ni filtre, et le paramètre `origine_reseau` n'est jamais envoyé. Un 422 est affiché tel quel.
 * Adresses en `example.invalid` uniquement (dépôt PUBLIC).
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { PersonnesPage } from '@/features/crm-console/PersonnesPage';
import type { PersonnesCounts } from '@/features/crm-console/types';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, getStatus } from '../msw/handlers';

const COUNTS: PersonnesCounts = {
  total: 2,
  by_statut_lettre: { abonne: 0, desabonne: 0, aucun: 2 },
  by_nature: { pro: 2, perso: 0, inconnue: 0 },
  by_source: { 'guide-ia': 2 },
  rattachees: 0,
  non_rattachees: 2,
};
const CURSEUR = { per_page: 50, next_cursor: null, prev_cursor: null, has_more: false };

const ligne = (id: number, extra: Record<string, unknown> = {}) => ({
  id,
  person_key: String(id).repeat(64).slice(0, 64),
  email: `zz.reseau${id}@example.invalid`,
  first_name: null,
  last_name: null,
  email_nature: 'pro',
  locale: 'fr',
  premiere_source: 'guide-ia',
  premiere_source_at: '2026-09-24T10:00:00Z',
  derniere_interaction_at: '2026-09-24T10:00:00Z',
  legal_basis: 'legitimate_interest_b2b',
  statut_lettre: null,
  base_lettre: null,
  prospection_autorisee: true,
  placement: null,
  rattachee: false,
  contact_id: null,
  company_id: null,
  entreprise: null,
  purge_prevue_le: null,
  opposition: { opposee: false, motifs: [] },
  ...extra,
});

const LISTE = {
  data: [ligne(1, { reseaux: ['facebook', 'linkedin'] }), ligne(2, { reseaux: [] }), ligne(3)],
  meta: CURSEUR,
};
const PAR_RESEAU = {
  actif: true,
  data: [
    { reseau: 'linkedin', canal: 'commentaire', compte: 'Page principale', demandes: 3, personnes: 2 },
    { reseau: 'instagram', canal: 'publication', compte: 'Page principale', demandes: 1, personnes: 1 },
  ],
};

async function monter(parReseau: ReturnType<typeof getJson>, liste: ReturnType<typeof getJson> = getJson('/crm/personnes', LISTE)) {
  await renderScreen(<PersonnesPage />, {
    path: '/console/lettre-et-guide',
    url: '/console/lettre-et-guide',
    consoleFeatures: 'open',
    handlers: [parReseau, liste, getJson('/crm/personnes/counts', COUNTS)],
  });
}

describe('PO-LE — colonne et filtre « Réseau »', () => {
  it('404 sur par-reseau ⇒ ni colonne ni filtre, et aucun paramètre origine_reseau envoyé', async () => {
    const vus: string[] = [];
    await monter(getStatus('/crm/origines/par-reseau', 404), http.get(apiUrl('/crm/personnes'), ({ request }) => {
      vus.push(request.url);
      return HttpResponse.json(LISTE);
    }));
    expect(await screen.findByText('zz.reseau1@example.invalid')).toBeVisible();
    expect(screen.queryByLabelText('Réseau')).toBeNull();
    expect(screen.queryByText(/Réseau\s*:/)).toBeNull();
    expect(screen.queryByText('LinkedIn')).toBeNull();
    expect(vus.length).toBeGreaterThan(0);
    expect(vus.every((u) => !u.includes('origine_reseau'))).toBe(true);
  });

  it('actif: false ⇒ ni colonne ni filtre', async () => {
    await monter(getJson('/crm/origines/par-reseau', { actif: false }));
    expect(await screen.findByText('zz.reseau1@example.invalid')).toBeVisible();
    expect(screen.queryByLabelText('Réseau')).toBeNull();
  });

  it('actif ⇒ colonne (libellés) et filtre ; « — » pour une personne sans origine', async () => {
    await monter(getJson('/crm/origines/par-reseau', PAR_RESEAU));
    const filtre = await screen.findByLabelText('Réseau');
    expect(within(filtre).getByRole('option', { name: 'LinkedIn' })).toBeInTheDocument();
    expect(within(filtre).getByRole('option', { name: 'Instagram' })).toBeInTheDocument();
    const l1 = (await screen.findByText('zz.reseau1@example.invalid')).closest('li')!;
    expect(within(l1).getByText('Facebook, LinkedIn')).toBeVisible();
    const l2 = screen.getByText('zz.reseau2@example.invalid').closest('li')!;
    expect(within(l2).getByTestId('cellule-reseau')).toHaveTextContent('—');
    const l3 = screen.getByText('zz.reseau3@example.invalid').closest('li')!;
    expect(within(l3).getByTestId('cellule-reseau')).toHaveTextContent('—');
  });

  it('choisir un réseau envoie le paramètre origine_reseau', async () => {
    const vus: string[] = [];
    await monter(getJson('/crm/origines/par-reseau', PAR_RESEAU), http.get(apiUrl('/crm/personnes'), ({ request }) => {
      vus.push(request.url);
      return HttpResponse.json(LISTE);
    }));
    await userEvent.selectOptions(await screen.findByLabelText('Réseau'), 'linkedin');
    await waitFor(() => expect(vus.some((u) => new URL(u).searchParams.get('origine_reseau') === 'linkedin')).toBe(true));
  });

  it('422 origine_lecture_inactive ⇒ message affiché tel quel', async () => {
    await monter(getJson('/crm/origines/par-reseau', PAR_RESEAU), http.get(apiUrl('/crm/personnes'), ({ request }) => {
      if (new URL(request.url).searchParams.has('origine_reseau')) {
        return HttpResponse.json({ message: 'La lecture des origines est inactive.', errors: { origine_reseau: ['origine_lecture_inactive'] } }, { status: 422 });
      }
      return HttpResponse.json(LISTE);
    }));
    await userEvent.selectOptions(await screen.findByLabelText('Réseau'), 'linkedin');
    expect(await screen.findByText(/La lecture des origines est inactive\./)).toBeVisible();
  });
});
