/**
 * GARDE — l'onglet « Fédérations » (chantier 3, 2026-09-29).
 *
 *  1. les filtres proposent EXACTEMENT le référentiel généré depuis le serveur
 *     (familles, niveaux, pertinences…), et le filtre « secteur représenté »
 *     ne propose pas « Non classé » (le serveur le refuserait en 422) ;
 *  2. un filtre choisi part bien au serveur (il ne fait pas que s'afficher) ;
 *  3. la fiche montre l'arborescence (têtes et antennes), les contacts, les
 *     événements, et faire avancer le partenariat envoie la bonne démarche.
 *
 * Fixtures FICTIVES (dépôt public).
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { FederationDetailPage } from '@/features/federations/FederationDetailPage';
import { FederationsPage } from '@/features/federations/FederationsPage';
import { FAMILLES_FEDERATION, NIVEAUX_FEDERATION, SECTEURS } from '@/lib/referentiels.generated';
import { renderScreen } from '../helpers/renderScreen';
import { apiUrl, getJson, recordGet } from '../msw/handlers';

const LIGNE = {
  id: 7,
  denomination: 'ZZ Fédération fictive du bâtiment',
  sigle: 'ZZFFB',
  entity_nature: 'federation',
  city: 'LYON',
  department_code: '69',
  region_code: '84',
  famille: 'federation_syndicat_pro',
  niveau: 'departemental',
  secteurs: ['btp'],
  tailles_adherents: ['tpe', 'pme'],
  pertinence: 'haute',
  contactabilite: 'email_verifie',
  certitude: 'haute',
  partenariat: 'aucun',
  partenariat_relance_at: null,
  tete: { id: 3, denomination: 'ZZ Fédération nationale' },
  nb_antennes: 2,
  evenement_a_venir: true,
};

const LISTE = { data: [LIGNE], meta: { total: 1, page: 1, per_page: 50 } };

async function monterListe() {
  const liste = recordGet('/federations', LISTE);
  await renderScreen(<FederationsPage />, {
    path: '/federations',
    handlers: [liste.handler],
    landingRoutes: ['/federations/$companyId'],
  });
  await screen.findByText('ZZ Fédération fictive du bâtiment');
  return liste;
}

function valeurs(label: string): string[] {
  return within(screen.getByLabelText(label))
    .getAllByRole('option')
    .map((o) => (o as HTMLOptionElement).value);
}

describe('onglet Fédérations — liste', () => {
  it('affiche la ligne avec ses libellés, sa tête de réseau et ses antennes', async () => {
    await monterListe();

    const table = within(screen.getByRole('table'));
    expect(table.getByText('Fédération ou syndicat professionnel')).toBeInTheDocument();
    expect(table.getByText('Départemental')).toBeInTheDocument();
    expect(table.getByText('Bâtiment et travaux publics')).toBeInTheDocument();
    expect(table.getByRole('link', { name: 'ZZ Fédération nationale' })).toBeInTheDocument();
    expect(table.getByText('2 antennes')).toBeInTheDocument();
    expect(table.getByText('Événement à venir')).toBeInTheDocument();
  });

  it('propose exactement les référentiels générés, sans « Non classé » en secteur représenté', async () => {
    await monterListe();

    expect(valeurs('Famille')).toEqual(['', ...FAMILLES_FEDERATION.map((f) => f.code)]);
    expect(valeurs('Niveau')).toEqual(['', ...NIVEAUX_FEDERATION.map((n) => n.code)]);
    const secteurs = valeurs('Secteur représenté');
    expect(secteurs).not.toContain('non_classe');
    expect(secteurs).toContain('interprofessionnel');
    expect(secteurs).toHaveLength(SECTEURS.length); // '' + tous sauf non_classe
  });

  it('envoie les filtres choisis au serveur', async () => {
    const liste = await monterListe();

    await userEvent.selectOptions(screen.getByLabelText('Famille'), 'ordre');
    await userEvent.selectOptions(screen.getByLabelText('Secteur représenté'), 'sante');
    await userEvent.selectOptions(screen.getByLabelText('Événement à venir'), '1');
    await userEvent.type(screen.getByLabelText('Département'), '69');

    await waitFor(() => {
      const derniere = decodeURIComponent(liste.urls[liste.urls.length - 1] ?? '');
      expect(derniere).toContain('famille=ordre');
      expect(derniere).toContain('secteur=sante');
      expect(derniere).toContain('evenement_a_venir=1');
      expect(derniere).toContain('departement=69');
      expect(derniere).toContain('page=1');
    });
  });
});

const FICHE = {
  ...LIGNE,
  siren: '900000007',
  naf: '94.11Z',
  legal_form: '9220',
  effectif_range: '11',
  address: '1 RUE FICTIVE 69001 LYON',
  postcode: '69001',
  sector_main: 'btp',
  website: 'https://zz-fede.example.invalid',
  linkedin_url: null,
  phone: null,
  email_generic: 'c***@zz-fede.example.invalid',
  contact_form_url: null,
  nom_developpe: null,
  date_creation: '1990-01-01',
  nb_etablissements: 1,
  origine_classement: 'examen',
  partenariat_note: null,
  ascendants: [
    { id: 5, denomination: 'ZZ Fédération régionale', niveau: 'regional' },
    { id: 3, denomination: 'ZZ Fédération nationale', niveau: 'national' },
  ],
  antennes: [{ id: 11, denomination: 'ZZ Antenne locale', niveau: 'local', city: 'VILLEURBANNE', department_code: '69' }],
  contacts: [
    {
      id: 21,
      first_name: 'Zoé',
      last_name: 'ZZPRÉSIDENTE',
      role: 'Présidente',
      email: 'z***@zz-fede.example.invalid',
      phone: null,
      linkedin_url: null,
      informe: false,
    },
  ],
  evenements: [
    {
      id: 31,
      nom: 'ZZ Assemblée générale',
      type: 'conference',
      date_debut: '2026-11-20',
      date_fin: null,
      recurrence: null,
      ville: 'LYON',
      participation: 'repere',
      intervention: 'aucune',
    },
  ],
  historique: [],
};

describe('onglet Fédérations — fiche', () => {
  it('montre la chaîne des têtes (racine d abord), les antennes, les contacts et les événements', async () => {
    await renderScreen(<FederationDetailPage />, {
      path: '/federations/$companyId',
      url: '/federations/7',
      handlers: [getJson('/federations/7', FICHE)],
      landingRoutes: ['/federations', '/evenements/$eventId', '/companies/$companyId'],
    });

    const chaine = await screen.findByRole('navigation', { name: 'Têtes de réseau' });
    const liens = within(chaine).getAllByRole('link').map((l) => l.textContent);
    expect(liens).toEqual(['ZZ Fédération nationale', 'ZZ Fédération régionale']);
    expect(screen.getByRole('link', { name: 'ZZ Antenne locale' })).toBeInTheDocument();
    expect(screen.getByText('Zoé ZZPRÉSIDENTE')).toBeInTheDocument();
    expect(screen.getByText('Non — mention au premier message')).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'ZZ Assemblée générale' })).toBeInTheDocument();
  });

  it('faire avancer le partenariat envoie la démarche choisie', async () => {
    const corps: unknown[] = [];
    await renderScreen(<FederationDetailPage />, {
      path: '/federations/$companyId',
      url: '/federations/7',
      handlers: [
        getJson('/federations/7', FICHE),
        http.patch(apiUrl('/federations/7/demarche'), async ({ request }) => {
          corps.push(await request.json());
          return HttpResponse.json({ ...FICHE, partenariat: 'propose' });
        }),
      ],
      landingRoutes: ['/federations', '/evenements/$eventId', '/companies/$companyId'],
    });

    await userEvent.selectOptions(await screen.findByLabelText('Étape'), 'propose');
    await userEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    await waitFor(() => expect(corps).toHaveLength(1));
    expect(corps[0]).toMatchObject({ partenariat: 'propose', partenariat_relance_at: null, partenariat_note: null });
  });
});
