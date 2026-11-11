/**
 * DM1-ENT — Entreprises en démo : export de la sélection, vues sauvegardées,
 * et ce que la fiche montre d'Envoi.
 *
 * Le faux serveur est le MÊME que celui de la démo (`entreprises.handlers.ts`).
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { http, HttpResponse } from 'msw';

import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { CompanyDetailPage } from '@/features/companies/CompanyDetailPage';
import { csv, handlers, NOM_VUE_MAX } from '@/demo/handlers/crm/entreprises.handlers';
import { zoneEntreprises } from '@/demo/donnees/crm/entreprises';
import { filtreDeLaVue, type VueSauvegardee } from '@/features/companies/vues/vuesSauvegardees';
import { EMPTY_FILTER } from '@/features/companies/filtresUrl';
import type { Entreprise } from '@/demo/donnees/types';
import { demoDb } from '@/demo/base';
import { api } from '@/demo/outils';
import { exporterSelection } from '@/features/companies/export/exporterSelection';
import { renderScreen } from '../helpers/renderScreen';
import { server } from '../msw/server';

const requetesExport: string[] = [];
const creerUrl = vi.fn((_blob: Blob | MediaSource) => 'blob:demo');
const espion = http.get(api('/companies/export'), ({ request }) => {
  requetesExport.push(request.url);
  return new HttpResponse('SIREN,Dénomination\n', { headers: { 'Content-Type': 'text/csv' } });
});
// Les deux écrans appellent ces routes, que d'autres lots du faux serveur remplissent.
const voisins = [
  http.get(api('/tags'), () => HttpResponse.json({ data: [] })),
  http.get(api('/listes-manuelles'), () => HttpResponse.json({ data: [] })),
];

beforeEach(() => {
  // jsdom ne mesure rien : sans hauteur, la liste virtualisée n'affiche aucune ligne.
  vi.spyOn(HTMLElement.prototype, 'offsetHeight', 'get').mockReturnValue(600);
  vi.spyOn(HTMLElement.prototype, 'offsetWidth', 'get').mockReturnValue(1000);
  vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({ width: 1000, height: 600, top: 0, left: 0, right: 1000, bottom: 600, x: 0, y: 0, toJSON: () => ({}) });
  demoDb.reinitialiser();
  requetesExport.length = 0;
  creerUrl.mockClear();
  URL.createObjectURL = creerUrl;
  URL.revokeObjectURL = vi.fn();
});
afterEach(() => vi.restoreAllMocks());

async function listeAffichee() {
  return renderScreen(<CompaniesListPage />, {
    path: '/companies',
    url: '/companies',
    handlers: [espion, ...handlers, ...voisins],
  });
}

describe('Entreprises : exporter la sélection', () => {
  it('3 lignes cochées : la requête porte ces 3 fiches et un fichier est proposé', async () => {
    const user = userEvent.setup();
    await listeAffichee();
    await screen.findByText('Boulangerie des Tilleuls');
    const cases = screen.getAllByRole('checkbox', { name: /^Sélectionner (?!toutes)/ });
    await user.click(cases[0]!);
    await user.click(cases[1]!);
    await user.click(cases[2]!);
    expect(await screen.findByText('3 sélectionnées')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Exporter la sélection' }));

    await waitFor(() => expect(requetesExport).toHaveLength(1));
    const ids = new URL(requetesExport[0]!).searchParams.get('filter[ids]');
    expect(ids?.split(',')).toHaveLength(3);
    await waitFor(() => expect(creerUrl).toHaveBeenCalledTimes(1));
  });

  it('serveur qui n’annonce pas l’export de sélection (le vrai, aujourd’hui) : pas de bouton, même avec des fiches cochées', async () => {
    // Réponse au format du vrai `GET /companies` : pas de `meta.export_selection`.
    // Le vrai `/companies/export` ignore `filter[ids]` : montrer le bouton
    // exporterait TOUTE la liste.
    const listeReelle = http.get(api('/companies'), () =>
      HttpResponse.json({
        data: [
          { id: 9001, siren: '000000001', denomination: 'Boulangerie des Tilleuls' },
          { id: 9002, siren: '000000002', denomination: 'Atelier Vernet' },
        ],
        meta: { total: 2, last_page: 1, current_page: 1, per_page: 100 },
      }),
    );
    const user = userEvent.setup();
    await renderScreen(<CompaniesListPage />, {
      path: '/companies',
      url: '/companies',
      handlers: [espion, listeReelle, ...handlers, ...voisins],
    });
    await screen.findByText('Boulangerie des Tilleuls');
    const cases = screen.getAllByRole('checkbox', { name: /^Sélectionner (?!toutes)/ });
    await user.click(cases[0]!);
    await user.click(cases[1]!);
    expect(await screen.findByText('2 sélectionnées')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Exporter la sélection' })).not.toBeInTheDocument();
    expect(requetesExport).toHaveLength(0);
  });

  it('une sélection vide ne part jamais (sinon l’export porterait sur toute la liste)', async () => {
    // L'espion répond : seule la garde du code peut empêcher la requête.
    server.use(espion);
    await expect(exporterSelection([])).rejects.toThrow();
    expect(requetesExport).toHaveLength(0);
  });

  it('sans sélection, le bouton n’existe pas', async () => {
    await listeAffichee();
    await screen.findByText('Boulangerie des Tilleuls');
    expect(screen.queryByRole('button', { name: 'Exporter la sélection' })).not.toBeInTheDocument();
  });
});

describe('Entreprises : vues sauvegardées', () => {
  it('enregistrer une vue la remet dans la liste, et recharger la garde', async () => {
    const user = userEvent.setup();
    const premier = await renderScreen(<CompaniesListPage />, {
      path: '/companies',
      url: '/companies?department_code=69',
      handlers: [...handlers, ...voisins],
    });
    await user.click(await screen.findByRole('button', { name: 'Enregistrer cette vue' }));
    await user.type(screen.getByRole('textbox', { name: 'Nom de la vue' }), 'Rhône, à relancer');
    await user.click(screen.getByRole('button', { name: 'Enregistrer' }));

    const vues = await screen.findByRole('combobox', { name: 'Vues enregistrées' });
    await waitFor(() => expect(within(vues).getByRole('option', { name: 'Rhône, à relancer' })).toBeInTheDocument());

    // « Recharger » : on démonte, on remonte — la vue est toujours là.
    premier.unmount();
    await renderScreen(<CompaniesListPage />, { path: '/companies', url: '/companies', handlers: [...handlers, ...voisins] });
    const apres = await screen.findByRole('combobox', { name: 'Vues enregistrées' });
    await waitFor(() => expect(within(apres).getByRole('option', { name: 'Rhône, à relancer' })).toBeInTheDocument());
  });

  it('un nom vide est refusé', async () => {
    const user = userEvent.setup();
    await listeAffichee();
    await screen.findByText('Boulangerie des Tilleuls');
    await user.click(screen.getByRole('button', { name: 'Enregistrer cette vue' }));
    expect(screen.getByRole('button', { name: 'Enregistrer' })).toBeDisabled();
  });
});

describe('Fiche entreprise : ce qui vient d’Envoi', () => {
  const idTilleuls = () => demoDb.entreprises.tous().find((e) => e.denomination === 'Boulangerie des Tilleuls')!.id;

  async function fiche(envoi: 'actif' | 'absent') {
    const features = http.get(api('/config/features'), () =>
      HttpResponse.json({ console_v2: true, universes: { business: true, vivier: false }, produits: { crm: 'actif', envoi } }),
    );
    return renderScreen(<CompanyDetailPage />, {
      path: '/companies/$companyId',
      url: `/companies/${idTilleuls()}`,
      handlers: [features, ...handlers, ...voisins],
    });
  }

  it('Envoi actif : résumé des envois et lien vers la campagne', async () => {
    await fiche('actif');
    expect(await screen.findByRole('heading', { name: 'Envois reçus' })).toBeInTheDocument();
    expect(screen.getByText(/Organisateurs Rhône-Alpes/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: 'Ajouter à une campagne' })).toHaveAttribute(
      'href',
      expect.stringContaining('/envoi/campagnes/nouvelle?entreprise='),
    );
  });

  it('Envoi absent : ni résumé, ni action de campagne', async () => {
    await fiche('absent');
    await screen.findByRole('heading', { level: 1, name: 'Boulangerie des Tilleuls' });
    expect(screen.queryByRole('heading', { name: 'Envois reçus' })).not.toBeInTheDocument();
    expect(screen.queryByRole('link', { name: 'Ajouter à une campagne' })).not.toBeInTheDocument();
  });

  it('montre responsable, affaire, historique et liens entre entreprises', async () => {
    await fiche('actif');
    expect(await screen.findByRole('heading', { name: 'Liens entre entreprises' })).toBeInTheDocument();
    // Un vrai lien vers la fiche de la holding (même nom), pas un simple texte.
    const holding = demoDb.entreprises.tous().find((e) => e.denomination === 'Groupe Tilleuls Holding')!;
    expect(holding).toBeDefined();
    const lien = screen.getByRole('link', { name: 'Groupe Tilleuls Holding' });
    expect(lien).toHaveAttribute('href', `/companies/${holding.id}`);
    expect(holding.id).not.toBe(idTilleuls());
    expect(screen.getByText('Société mère')).toBeInTheDocument();
    expect(screen.getByText('Responsable', { selector: 'dt' })).toBeInTheDocument();
    expect(screen.getByRole('heading', { name: 'Historique' })).toBeInTheDocument();
  });

  it('serveur qui ne sert pas ces champs (le vrai, aujourd’hui) : aucune carte vide ni trompeuse', async () => {
    const ficheReelle = http.get(api('/companies/:id'), ({ params }) =>
      HttpResponse.json({ id: Number(params['id']), siren: '000000001', denomination: 'Boulangerie des Tilleuls', contacts: [], tags: [] }),
    );
    const features = http.get(api('/config/features'), () =>
      HttpResponse.json({ console_v2: true, universes: { business: true, vivier: false }, produits: { crm: 'actif', envoi: 'actif' } }),
    );
    await renderScreen(<CompanyDetailPage />, {
      path: '/companies/$companyId',
      url: `/companies/${idTilleuls()}`,
      handlers: [ficheReelle, features, ...handlers, ...voisins],
    });
    await screen.findByRole('heading', { level: 1, name: 'Boulangerie des Tilleuls' });
    expect(screen.queryByRole('heading', { name: 'Liens entre entreprises' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Historique' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Suivi commercial' })).not.toBeInTheDocument();
    expect(screen.queryByRole('heading', { name: 'Envois reçus' })).not.toBeInTheDocument();
    expect(screen.queryByText('Non attribué')).not.toBeInTheDocument();
    expect(screen.queryByText('Aucune affaire en cours')).not.toBeInTheDocument();
  });

  it('l’étape posée à la main est annoncée comme définitive pour l’import (elle ne recule jamais seule)', async () => {
    await fiche('actif');
    expect(await screen.findByText(/l’import ne la modifiera plus/)).toBeInTheDocument();
  });
});

describe('csv() du faux serveur : la vraie fonction (DM1-ENT)', () => {
  const une = (surcharge: Partial<Entreprise>): Entreprise => ({ ...demoDb.entreprises.tous()[0]!, ...surcharge });

  it('commence par le BOM, puis l’en-tête', () => {
    expect(csv([]).startsWith('﻿SIREN,Dénomination,')).toBe(true);
  });

  it.each(['=1+1', '+33', '-2', '@SOMME(A1)', '\tcellule', '\rcellule'])('neutralise %j en tête de cellule', (brut) => {
    const sortie = csv([une({ denomination: brut })]);
    // La cellule porte l'apostrophe devant (entourée de guillemets si elle contient un retour).
    expect(sortie.includes(`'${brut}`)).toBe(true);
    expect(sortie.includes(`,${brut}`)).toBe(false);
    expect(sortie.includes(`,"${brut}`)).toBe(false);
  });

  it('entoure de guillemets les virgules, retours à la ligne et guillemets (doublés)', () => {
    const sortie = csv([une({ denomination: 'A, B', city: 'Ligne\nDeux', website: 'dit "oui"' })]);
    expect(sortie).toContain('"A, B"');
    expect(sortie).toContain('"Ligne\nDeux"');
    expect(sortie).toContain('"dit ""oui"""');
  });

  it('neutralise d’abord, puis entoure : =a,b devient "\'=a,b"', () => {
    expect(csv([une({ denomination: '=a,b' })])).toContain('"\'=a,b"');
  });

  it('accepte une valeur absente sans écrire « null » ni « undefined »', () => {
    const sortie = csv([une({ phone: null as never, email_generic: undefined as never })]);
    expect(sortie).not.toMatch(/null|undefined/);
  });
});

describe('filtreDeLaVue (DM1-ENT)', () => {
  const vue = (filters: unknown): VueSauvegardee => ({ id: 1, name: 'v', filters: filters as never, is_default: false });

  it('reprend les clés connues valides et repart de zéro pour le reste', () => {
    expect(filtreDeLaVue(vue({ priority: 'haute' }))).toEqual({ ...EMPTY_FILTER, priority: 'haute' });
    expect(filtreDeLaVue(vue({}))).toEqual(EMPTY_FILTER);
  });

  it('ignore les clés inconnues', () => {
    expect(filtreDeLaVue(vue({ inconnue: 'x', priority: 'haute' }))).toEqual({ ...EMPTY_FILTER, priority: 'haute' });
  });

  it('ignore __proto__ et ne pollue aucun prototype', () => {
    const piege = JSON.parse('{"__proto__": {"priority": "haute"}, "constructor": "x"}') as unknown;
    expect(filtreDeLaVue(vue(piege))).toEqual(EMPTY_FILTER);
    expect(({} as Record<string, unknown>)['priority']).toBeUndefined();
  });

  it('ignore les valeurs qui ne sont pas du texte', () => {
    expect(filtreDeLaVue(vue({ priority: 3, tag: ['a'], naf: { x: 1 }, department_code: 69, fermees: true }))).toEqual(EMPTY_FILTER);
  });

  it('refuse une valeur texte que l’écran refuserait (même validation que l’adresse)', () => {
    expect(filtreDeLaVue(vue({ priority: 'nimporte-quoi', cree_apres: 'pas-une-date' }))).toEqual(EMPTY_FILTER);
  });

  it('une vue nulle ou sans filtres ne casse pas', () => {
    expect(filtreDeLaVue(vue(null))).toEqual(EMPTY_FILTER);
    expect(filtreDeLaVue(vue(undefined))).toEqual(EMPTY_FILTER);
  });
});

describe('POST /saved-views : nom de vue (DM1-ENT)', () => {
  const envoyer = (name: string) =>
    fetch('https://api.localhost/api/v1/saved-views', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ entity: 'companies', name, filters: {} }),
    });

  beforeEach(() => {
    demoDb.reinitialiser();
    zoneEntreprises();
    server.use(...handlers);
  });

  it('doublon de nom insensible à la casse', async () => {
    expect((await envoyer('Priorité Haute')).status).toBe(201);
    expect((await envoyer('priorité haute')).status).toBe(422);
    expect((await envoyer('PRIORITÉ HAUTE')).status).toBe(422);
  });

  it('plafonne la longueur du nom', async () => {
    expect((await envoyer('x'.repeat(NOM_VUE_MAX))).status).toBe(201);
    expect((await envoyer('y'.repeat(NOM_VUE_MAX + 1))).status).toBe(422);
  });
});

describe('Vivier Roumanie sans technique.handlers.ts (DM1-ENT)', () => {
  it('la liste RO crée la zone « technique » si elle est absente', async () => {
    demoDb.reinitialiser();
    server.use(...handlers);
    const reponse = await fetch('https://api.localhost/api/v1/companies?filter[country_code]=RO');
    expect(reponse.status).toBe(200);
    expect(Array.isArray(((await reponse.json()) as { data: unknown[] }).data)).toBe(true);
  });
});

describe('Liens de groupe (DM1-ENT)', () => {
  it('la holding porte le lien retour « Filiale » vers Boulangerie des Tilleuls', () => {
    demoDb.reinitialiser();
    const fiches = zoneEntreprises().fiches;
    const tilleuls = demoDb.entreprises.tous().find((e) => e.denomination === 'Boulangerie des Tilleuls')!;
    const holding = demoDb.entreprises.tous().find((e) => e.denomination === 'Groupe Tilleuls Holding')!;
    expect(fiches.find((f) => f.id === tilleuls.id)!.liens).toContainEqual({ company_id: holding.id, denomination: 'Groupe Tilleuls Holding', lien: 'Société mère' });
    expect(fiches.find((f) => f.id === holding.id)!.liens).toContainEqual({ company_id: tilleuls.id, denomination: 'Boulangerie des Tilleuls', lien: 'Filiale' });
  });
});
