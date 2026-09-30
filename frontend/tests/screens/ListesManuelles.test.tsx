/**
 * GARDE — les LISTES MANUELLES (2026-09-30).
 *
 *  1. l'écran des listes crée une liste nommée ;
 *  2. « Ajouter à une liste » envoie les fiches COCHÉES, et annonce le compte
 *     réel (déjà présentes, introuvables) ;
 *  3. retirer des fiches demande confirmation — et rien ne part si l'on
 *     refuse ; le message dit que la fiche elle-même n'est pas supprimée ;
 *  4. l'import s'ANALYSE d'abord (à blanc) : le bilan cite des numéros de
 *     ligne et des motifs ; l'import réel ne part qu'après ;
 *  5. la corbeille refusée (409) affiche le motif du serveur.
 *
 * Fixtures FICTIVES (dépôt public).
 */
import { afterEach, describe, it, expect, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { ListesManuellesPage } from '@/features/listes/ListesManuellesPage';
import { ListeManuelleDetailPage } from '@/features/listes/ListeManuelleDetailPage';
import { AjouterAUneListe } from '@/features/listes/AjouterAUneListe';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, http, HttpResponse, recordPost, type HttpHandler } from '../msw/handlers';

const notes = vi.hoisted(() => ({ succes: vi.fn(), erreur: vi.fn() }));
vi.mock('sonner', () => ({ toast: { success: notes.succes, error: notes.erreur } }));

const LISTE = {
  id: 7,
  nom: 'Invités salon ZZ',
  description: 'Salon d’octobre',
  organisations: 1,
  personnes: 1,
  contient: false,
  created_at: '2026-09-30T08:00:00+02:00',
  updated_at: '2026-09-30T08:00:00+02:00',
  deleted_at: null,
};

const MEMBRES = {
  data: [
    {
      id: 101, type: 'organisation', origine: 'coche', ajoute_le: '2026-09-30T08:00:00+02:00',
      company_id: 11, contact_id: null, organisation_id: 11, denomination: 'ZZ USINE', siren: '900000401',
      email_generic: 'c***@zz-usine.example.invalid', first_name: null, last_name: null, role: null, email: null,
    },
    {
      id: 102, type: 'personne', origine: 'import', ajoute_le: '2026-09-30T08:00:00+02:00',
      company_id: null, contact_id: 22, organisation_id: 12, denomination: 'ZZ ATELIER', siren: null,
      email_generic: null, first_name: 'Zoé', last_name: 'ZZDIRECTRICE', role: 'Présidente', email: 'z***@zz-atelier.example.invalid',
    },
  ],
  meta: { total: 2, page: 1, per_page: 50 },
};

afterEach(() => {
  vi.restoreAllMocks();
  notes.succes.mockReset();
  notes.erreur.mockReset();
});

async function monterDetail(extra: HttpHandler[] = []) {
  await renderScreen(<ListeManuelleDetailPage />, {
    path: '/listes/$listeId',
    url: '/listes/7',
    handlers: [getJson('/listes-manuelles/7', { data: LISTE }), getJson('/listes-manuelles/7/membres', MEMBRES), ...extra],
    landingRoutes: ['/listes', '/companies', '/companies/$companyId'],
  });
  await screen.findByTestId('membre-101');
}

describe('listes manuelles', () => {
  it('crée une liste nommée', async () => {
    const creation = recordPost('/listes-manuelles', { data: { ...LISTE, id: 8, nom: 'Invités GOFAB ZZ' } }, 201);
    await renderScreen(<ListesManuellesPage />, {
      path: '/listes',
      handlers: [getJson('/listes-manuelles', { data: [LISTE] }), creation.handler],
      landingRoutes: ['/listes/$listeId'],
    });
    expect(await screen.findByText('Invités salon ZZ')).toBeInTheDocument();

    await userEvent.type(screen.getByPlaceholderText('Ex. : Invités salon GOFAB'), 'Invités GOFAB ZZ');
    await userEvent.click(screen.getByRole('button', { name: 'Créer la liste' }));

    await waitFor(() => expect(creation.bodies).toHaveLength(1));
    expect(creation.bodies[0]).toEqual({ nom: 'Invités GOFAB ZZ' });
  });

  it('« Ajouter à une liste » envoie les fiches cochées et annonce le compte réel', async () => {
    const ajout = recordPost('/listes-manuelles/7/membres', { data: { ajoutes: 1, reactives: 0, deja_presents: 1, introuvables: 1 } });
    await renderScreen(<AjouterAUneListe companyIds={[11, 12]} contactIds={[22]} />, {
      handlers: [getJson('/listes-manuelles', { data: [LISTE] }), ajout.handler],
    });
    await screen.findByRole('option', { name: 'Invités salon ZZ' });

    await userEvent.selectOptions(screen.getByLabelText('Liste manuelle'), '7');
    await userEvent.click(screen.getByRole('button', { name: /Ajouter les 3 fiches/ }));

    await waitFor(() => expect(ajout.bodies).toHaveLength(1));
    expect(ajout.bodies[0]).toEqual({ company_ids: [11, 12], contact_ids: [22] });
    expect(notes.succes).toHaveBeenCalledWith('1 fiche(s) ajoutée(s), 1 déjà présente(s), 1 introuvable(s).');
  });

  it('retirer demande confirmation, envoie les BONNES fiches, et dit que la fiche reste', async () => {
    const confirmer = vi.spyOn(window, 'confirm').mockReturnValue(true);
    const retrait = recordPost('/listes-manuelles/7/membres/retirer', { data: { retires: 2, absents: 0 } });
    await monterDetail([retrait.handler]);

    await userEvent.click(screen.getByLabelText('Cocher ZZ USINE'));
    await userEvent.click(screen.getByLabelText('Cocher Zoé ZZDIRECTRICE'));
    await userEvent.click(screen.getByRole('button', { name: 'Retirer de la liste' }));

    await waitFor(() => expect(retrait.bodies).toHaveLength(1));
    expect(retrait.bodies[0]).toEqual({ company_ids: [11], contact_ids: [22] });
    expect(String(confirmer.mock.calls[0]?.[0])).toContain('ne sont pas supprimées');
    expect(notes.succes).toHaveBeenCalledWith(expect.stringContaining('intactes'));
  });

  it('témoin : confirmation refusée, AUCUN retrait ne part', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(false);
    const retrait = recordPost('/listes-manuelles/7/membres/retirer', { data: { retires: 1, absents: 0 } });
    await monterDetail([retrait.handler]);

    await userEvent.click(screen.getByLabelText('Cocher ZZ USINE'));
    await userEvent.click(screen.getByRole('button', { name: 'Retirer de la liste' }));
    await new Promise((r) => setTimeout(r, 50));

    expect(retrait.bodies).toHaveLength(0);
  });

  it('l import s ANALYSE d abord : bilan par motif et numéro de ligne, puis import réel', async () => {
    const envois: string[] = [];
    const contenus: string[] = [];
    const bilan = (aBlanc: boolean) => ({
      lignes_lues: 3, rapprochees: 2, rejetees: { introuvable: 1, format_inconnu: 0 }, doublons_dans_le_fichier: 0,
      rapprochees_a_plusieurs_fiches: 0, par_type: { crm: 0, siren: 2, email_personne: 0, email_organisation: 0 },
      organisations_retrouvees: 2, personnes_retrouvees: 0, exemples_rejets: [{ ligne: 4, motif: 'introuvable' }], a_blanc: aBlanc,
      ...(aBlanc ? {} : { ajout: { ajoutes: 2, reactives: 0, deja_presents: 0, introuvables: 0 } }),
    });
    const importHandler = http.post(apiUrl('/listes-manuelles/7/import'), async ({ request }) => {
      const corps = (await request.json()) as { contenu: string; a_blanc: boolean };
      const aBlanc = corps.a_blanc;
      // Le CONTENU du fichier part, tel quel.
      contenus.push(corps.contenu);
      envois.push(aBlanc ? 'a_blanc' : 'reel');
      return HttpResponse.json({ data: bilan(aBlanc) });
    });
    await monterDetail([importHandler]);

    const fichier = new File(['siren\n900000401\n900000402\n900000499\n'], 'invites.csv', { type: 'text/csv' });
    await userEvent.upload(screen.getByLabelText('Fichier à importer'), fichier);
    const importer = screen.getByRole('button', { name: /^Importer/ });
    expect(importer).toBeDisabled();
    // Le fichier est bien choisi : « Analyser » est actif.
    expect(screen.getByRole('button', { name: /Analyser/ })).toBeEnabled();

    await userEvent.click(screen.getByRole('button', { name: /Analyser/ }));
    await waitFor(() => expect({ envois, erreurs: notes.erreur.mock.calls }).toEqual({ envois: ['a_blanc'], erreurs: [] }));
    const statut = await screen.findByTestId('bilan-import');
    expect(within(statut).getByText(/rien n’a été écrit/)).toBeInTheDocument();
    expect(within(statut).getByText(/n° 4 \(absente du CRM\)/)).toBeInTheDocument();
    expect(envois).toEqual(['a_blanc']);

    await userEvent.click(screen.getByRole('button', { name: /^Importer 2 ligne/ }));
    await waitFor(() => expect(envois).toEqual(['a_blanc', 'reel']));
    expect(contenus[0]).toContain('900000499');
  });

  it('la corbeille refusée (liste utilisée par une audience) affiche le motif du serveur', async () => {
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    const refus = http.delete(apiUrl('/listes-manuelles/7'), () =>
      HttpResponse.json({ message: 'Cette liste sert au ciblage de 1 audience(s) : la retirer de leurs critères d’abord.' }, { status: 409 }),
    );
    await monterDetail([refus]);

    await userEvent.click(screen.getByRole('button', { name: 'Corbeille' }));

    await waitFor(() => expect(notes.erreur).toHaveBeenCalledWith(expect.stringContaining('sert au ciblage')));
    expect(notes.succes).not.toHaveBeenCalled();
  });
});
