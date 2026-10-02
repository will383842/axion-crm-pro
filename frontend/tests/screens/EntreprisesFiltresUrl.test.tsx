/**
 * GARDE — lot 4 (audit UX 2026-10-02, P1-4 et P2-4/5) : les filtres de
 * l'écran Entreprises vivent dans l'adresse, et l'adresse n'est pas crue sur
 * parole.
 *
 * Avant : le lien du tableau de bord `/companies?quality_badge=basique`
 * menait à la liste NON filtrée (l'écran ne lisait pas l'URL), et un
 * rechargement perdait tous les filtres.
 *
 * Ce que cette garde tient :
 *  1. un filtre de l'adresse arrive jusqu'à la requête serveur ;
 *  2. un paramètre invalide (valeur hors liste, texte trop long, objet) est
 *     IGNORÉ, il n'atteint jamais le serveur ;
 *  3. changer un filtre réécrit l'adresse ;
 *  4. les filtres secondaires sont repliés, et un filtre caché actif garde
 *     le panneau ouvert et se compte sur le bouton ;
 *  5. le bouton « Importer », qui ne faisait rien, a disparu.
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { RouterHistory } from '@tanstack/react-router';

import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import {
  rechercheDepuisFiltre,
  validerRechercheEntreprises,
  EMPTY_FILTER,
} from '@/features/companies/filtresUrl';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet } from '../msw/handlers';

const GEO = {
  regions: [{ code: '84', name: 'Auvergne-Rhône-Alpes' }],
  departments: [
    { code: '69', name: 'Rhône' },
    { code: '75', name: 'Paris' },
  ],
};
const PAGE_VIDE = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 },
};

async function monter(url: string) {
  const compagnies = recordGet('/companies', PAGE_VIDE);
  const vue = await renderScreen(<CompaniesListPage />, {
    path: '/companies',
    url,
    handlers: [compagnies.handler, getJson('/referentiels/geo', GEO)],
  });
  await waitFor(() => expect(compagnies.urls.length).toBeGreaterThanOrEqual(1));
  const premiere = decodeURIComponent(compagnies.urls[0] ?? '');
  return { compagnies, vue, premiere };
}

describe('validerRechercheEntreprises — l’adresse n’est pas sûre', () => {
  it('garde les valeurs connues, convertit les nombres, refuse le reste', () => {
    expect(
      validerRechercheEntreprises({
        quality: 'basique',
        department_code: 69,
        eligible_campagne: 1,
        size: 'n-importe-quoi',
        priority: { haute: true },
        cree_apres: '2026-02-30',
        cree_avant: '2026-09-01',
        inconnu: 'x',
      }),
    ).toEqual({
      quality: 'basique',
      department_code: '69',
      eligible_campagne: '1',
      cree_avant: '2026-09-01',
    });
  });

  it('borne les textes libres et refuse les caractères de contrôle', () => {
    expect(validerRechercheEntreprises({ search: 'x'.repeat(121) })).toEqual({});
    expect(validerRechercheEntreprises({ search: 'boulangerie' })).toEqual({ search: 'boulangerie' });
    expect(validerRechercheEntreprises({ tag: 'a\u0000b' })).toEqual({});
    expect(validerRechercheEntreprises({ naf: '68.31Z' })).toEqual({ naf: '68.31Z' });
    expect(validerRechercheEntreprises({ naf: "68'; DROP" })).toEqual({});
  });

  it('normalise le code NAF (espaces retirés, majuscules)', () => {
    expect(validerRechercheEntreprises({ naf: '68.31 z' })).toEqual({ naf: '68.31Z' });
  });

  it('accepte l’ancien nom `quality_badge` des liens existants', () => {
    expect(validerRechercheEntreprises({ quality_badge: 'basique' })).toEqual({ quality: 'basique' });
  });

  it('n’écrit dans l’adresse que les filtres renseignés', () => {
    expect(rechercheDepuisFiltre({ ...EMPTY_FILTER, size: 'pme' })).toEqual({ size: 'pme' });
  });
});

describe('écran Entreprises — filtres lus depuis l’adresse', () => {
  it('le lien du tableau de bord arrive filtré', async () => {
    const { premiere } = await monter('/companies?quality=basique&department_code=69');

    expect(premiere).toContain('filter[quality]=basique');
    expect(premiere).toContain('filter[department_code]=69');
    // « Qualité de la fiche » est un filtre secondaire ACTIF : le panneau
    // reste ouvert et le bouton le dit.
    expect(await screen.findByLabelText('Qualité de la fiche')).toHaveValue('basique');
    expect(screen.getByRole('button', { name: /Plus de filtres \(13\) · 1 actif/ })).toBeInTheDocument();
  });

  it('ignore un paramètre invalide', async () => {
    const { premiere } = await monter(
      `/companies?quality=pirate&size=autre&search=${'x'.repeat(500)}&department_code=abc`,
    );

    expect(premiere).not.toContain('filter[quality]');
    expect(premiere).not.toContain('filter[size_category]');
    expect(premiere).not.toContain('filter[denomination]');
    expect(premiere).not.toContain('filter[department_code]');
    // Aucun filtre actif : pas de bouton d'effacement.
    expect(screen.queryByRole('button', { name: 'Effacer les filtres' })).toBeNull();
  });

  it('changer un filtre réécrit l’adresse, « Effacer les filtres » la vide', async () => {
    const { vue, compagnies } = await monter('/companies');

    await userEvent.selectOptions(await screen.findByLabelText('Département'), '75');

    await waitFor(() => {
      expect(vue.router.state.location.search).toEqual({ department_code: '75' });
    });
    await waitFor(() => {
      const derniere = decodeURIComponent(compagnies.urls[compagnies.urls.length - 1] ?? '');
      expect(derniere).toContain('filter[department_code]=75');
    });

    await userEvent.click(screen.getByRole('button', { name: 'Effacer les filtres' }));
    await waitFor(() => {
      expect(vue.router.state.location.search).toEqual({});
    });
  });

  it('replie les filtres secondaires et retire le bouton « Importer »', async () => {
    await monter('/companies');

    expect(screen.getByLabelText('Département')).toBeInTheDocument();
    expect(screen.getByLabelText('Taille')).toBeInTheDocument();
    expect(screen.getByLabelText('Secteur d’activité')).toBeInTheDocument();
    expect(screen.queryByLabelText('Priorité')).toBeNull();

    await userEvent.click(screen.getByRole('button', { name: 'Plus de filtres (13)' }));
    expect(screen.getByLabelText('Priorité')).toBeInTheDocument();

    expect(screen.queryByRole('button', { name: /Importer/ })).toBeNull();
    // Un seul « Toutes qualités » à l'écran (il y en avait deux).
    expect(screen.getAllByRole('option', { name: 'Toutes qualités' })).toHaveLength(1);
  });

  it('`?quality_badge=basique` (ancien lien) arrive jusqu’à la requête', async () => {
    const { premiere } = await monter('/companies?quality_badge=basique');
    expect(premiere).toContain('filter[quality]=basique');
  });

  it('se resynchronise quand l’adresse change de l’extérieur', async () => {
    const { vue, compagnies } = await monter('/companies?department_code=69');

    await vue.router.navigate({ to: '/companies', search: { size: 'pme' } });

    await waitFor(() => expect(screen.getByLabelText('Taille')).toHaveValue('pme'));
    expect(screen.getByLabelText('Département')).toHaveValue('');
    await waitFor(() => {
      const derniere = decodeURIComponent(compagnies.urls[compagnies.urls.length - 1] ?? '');
      expect(derniere).toContain('filter[size_category]=pme');
      expect(derniere).not.toContain('filter[department_code]');
    });
  });

  it('écrit la recherche dans l’adresse après l’anti-rebond, sans ajouter d’historique', async () => {
    const { vue } = await monter('/companies');
    const historique = vue.router.history as RouterHistory;
    const longueurAvant = historique.length;

    await userEvent.type(await screen.findByPlaceholderText('Rechercher une entreprise…'), 'boulangerie');

    await waitFor(() => {
      expect(vue.router.state.location.search).toEqual({ search: 'boulangerie' });
    });
    // Remplacement, pas une entrée par lettre : « Précédent » quitte l'écran.
    expect(historique.length).toBe(longueurAvant);
  });

  it('« Effacer les filtres » ne renvoie JAMAIS l’ancienne recherche', async () => {
    const { vue, compagnies } = await monter('/companies?department_code=75');
    const adresses: string[] = [];
    const historique = vue.router.history as RouterHistory;
    const desabonner = historique.subscribe(() => {
      adresses.push(historique.location.search);
    });

    await userEvent.type(await screen.findByPlaceholderText('Rechercher une entreprise…'), 'boul');
    await waitFor(() => {
      const derniere = decodeURIComponent(compagnies.urls[compagnies.urls.length - 1] ?? '');
      expect(derniere).toContain('filter[denomination]=boul');
    });
    const requetesAvant = compagnies.urls.length;
    const adressesAvant = adresses.length;

    await userEvent.click(screen.getByRole('button', { name: 'Effacer les filtres' }));
    // Plus que la fenêtre d'anti-rebond (300 ms), pour laisser la valeur périmée se montrer si elle existait.
    await new Promise((r) => setTimeout(r, 600));
    desabonner();

    const requetesApres = compagnies.urls.slice(requetesAvant).map((u) => decodeURIComponent(u));
    expect(requetesApres.length).toBeGreaterThanOrEqual(1);
    expect(requetesApres.filter((u) => u.includes('boul'))).toEqual([]);
    expect(adresses.slice(adressesAvant).filter((a) => a.includes('boul'))).toEqual([]);
    expect(vue.router.state.location.search).toEqual({});
  });

  it('code NAF tapé avec des espaces : la liste et l’adresse reçoivent la même valeur', async () => {
    const { vue, compagnies } = await monter('/companies');

    await userEvent.click(screen.getByRole('button', { name: 'Plus de filtres (13)' }));
    await userEvent.type(screen.getByLabelText('Code NAF'), '68.31 z');

    await waitFor(() => {
      const derniere = decodeURIComponent(compagnies.urls[compagnies.urls.length - 1] ?? '');
      expect(derniere).toContain('filter[naf]=68.31Z');
    });
    await waitFor(() => expect(vue.router.state.location.search).toEqual({ naf: '68.31Z' }));
  });
});
