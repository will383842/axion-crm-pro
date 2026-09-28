/**
 * GARDE — le filtre « Nature » de l'écran Entreprises propose les VRAIES natures.
 *
 * ── Le défaut (audit du 2026-09-28, chantier « référentiels ») ────────────
 * La liste était recopiée à la main dans `prospection-referentiels.ts` :
 *   « Entreprises, Associations, Institutions, Autres ».
 * `autre` n'existe pas en base (CHECK `companies_entity_nature_check`) : le
 * choisir rendait une liste VIDE, qui se lisait « aucun résultat ». Et cinq
 * natures réelles manquaient : CCI, réseaux, médias, cabinets, enseignement.
 *
 * ── Ce que cette garde tient ──────────────────────────────────────────────
 *  1. les options sont EXACTEMENT le référentiel généré depuis le serveur
 *     (`referentiels.generated.ts`, lui-même gardé côté Pest contre
 *     `Taxonomy::ENTITY_NATURES`) ;
 *  2. indépendamment de ce fichier, les huit natures de la base y sont, et
 *     « autre » n'y est pas — sinon un fichier généré vidé passerait le 1 ;
 *  3. choisir une nature envoie bien `filter[entity_nature]=<valeur>` au
 *     serveur (le filtre sert à quelque chose, il ne fait pas que s'afficher).
 */
import { describe, it, expect } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

import { CompaniesListPage } from '@/features/companies/CompaniesListPage';
import { NATURES } from '@/lib/referentiels.generated';
import { renderScreen } from '../helpers/renderScreen';
import { getJson, recordGet } from '../msw/handlers';

const GEO = {
  regions: [{ code: '11', name: 'Ile-de-France' }],
  departments: [{ code: '75', name: 'Paris' }],
};
const PAGE_VIDE = {
  data: [],
  meta: { current_page: 1, last_page: 1, per_page: 100, total: 0 },
};

/** Les huit natures du CHECK en base (migration 2026_09_27_000002). */
const NATURES_EN_BASE = [
  'entreprise',
  'association',
  'cci',
  'enseignement',
  'cabinet',
  'institution',
  'media',
  'reseau',
];

async function monter() {
  const compagnies = recordGet('/companies', PAGE_VIDE);
  await renderScreen(<CompaniesListPage />, {
    path: '/companies',
    handlers: [compagnies.handler, getJson('/referentiels/geo', GEO)],
  });
  await waitFor(() => expect(compagnies.urls.length).toBeGreaterThanOrEqual(1));
  const filtre = await screen.findByLabelText("Filtre nature d'entité");
  const valeurs = within(filtre)
    .getAllByRole('option')
    .map((o) => (o as HTMLOptionElement).value);
  return { compagnies, filtre, valeurs };
}

describe('filtre Nature — écran Entreprises', () => {
  it('propose exactement les natures du référentiel, sans « autre »', async () => {
    const { valeurs } = await monter();

    // 1. le référentiel généré, et rien d'autre (plus l'option « toutes »).
    expect(valeurs).toEqual(['', ...NATURES.map((n) => n.code)]);

    // 2. vérifié sans passer par le fichier généré.
    expect([...valeurs].sort()).toEqual(['', ...NATURES_EN_BASE].sort());
    expect(valeurs).not.toContain('autre');
  });

  it('envoie la nature choisie au serveur', async () => {
    const { compagnies, filtre } = await monter();

    await userEvent.selectOptions(filtre, 'cci');

    await waitFor(() => {
      const derniere = compagnies.urls[compagnies.urls.length - 1] ?? '';
      expect(decodeURIComponent(derniere)).toContain('filter[entity_nature]=cci');
    });
  });
});
