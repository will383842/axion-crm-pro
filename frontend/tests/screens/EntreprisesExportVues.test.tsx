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
import { handlers } from '@/demo/handlers/crm/entreprises.handlers';
import { handlers as handlersSocle } from '@/demo/handlers/socle/config.handlers';
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
      handlers: [features, ...handlersSocle.slice(0, 0), ...handlers, ...voisins],
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
    expect(screen.getByText('Groupe Tilleuls Holding')).toBeInTheDocument();
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
