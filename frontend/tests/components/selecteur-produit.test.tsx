/**
 * DM0-COQ — LE SÉLECTEUR DE PRODUIT (ADR-14, maquettes M2 validées).
 *
 * Ce que la garde tient :
 *  1. un seul produit (le cas d'Axion en production tant qu'Envoi est absent) :
 *     AUCUN sélecteur — la barre reste le menu de Will ;
 *  2. les deux produits actifs : le sélecteur est là, en haut de la barre ;
 *  3. passer à Envoi montre les 10 entrées des maquettes validées, et la
 *     coquille prend l'identité d'Envoi (turquoise pétrole) ;
 *  4. revenir au CRM rend la DERNIÈRE page ouverte du CRM, pas l'accueil.
 */
import { beforeEach, describe, expect, it } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { renderScreen } from '../helpers/renderScreen';
import { FEATURES_ENVOI_SEUL, FEATURES_LES_DEUX_PRODUITS, getJson, handlersEnvoi } from '../msw/handlers';

/** Les 10 entrées d'Envoi, dans l'ordre des maquettes validées (campagnes.html). */
const ENTREES_ENVOI: ReadonlyArray<readonly [string, string]> = [
  ['/envoi/campagnes', 'Campagnes'],
  ['/envoi/reponses', 'Réponses'],
  ['/envoi/boites', 'Boîtes d’envoi'],
  ['/envoi/domaines', 'Domaines'],
  ['/envoi/serveurs', 'Serveurs et adresses IP'],
  ['/envoi/chauffe', 'Chauffe'],
  ['/envoi/sante', 'Santé des envois'],
  ['/envoi/desinscriptions', 'Désinscriptions'],
  ['/envoi/alertes', 'Alertes d’envoi'],
  ['/envoi/reglages', 'Réglages d’envoi'],
];

const ATTERRISSAGES = ['/', '/companies', '/envoi/campagnes', '/envoi/domaines'];

function racineDeLaCoquille(): HTMLElement {
  const racine = document.querySelector<HTMLElement>('[data-coquille]');
  expect(racine, 'La coquille doit porter `data-coquille` (RootLayout).').not.toBeNull();
  return racine as HTMLElement;
}

async function monter(chemin: string, features: unknown) {
  const vue = await renderScreen(<p>Contenu de la page</p>, {
    path: chemin,
    withLayout: true,
    handlers: [getJson('/config/features', features), ...handlersEnvoi()],
    landingRoutes: ATTERRISSAGES,
  });
  await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
  return vue;
}

beforeEach(() => {
  window.sessionStorage.clear();
});

describe('Sélecteur de produit', () => {
  it('est CACHÉ avec un seul produit (défaut : Envoi absent)', async () => {
    const vue = await renderScreen(<p>Contenu de la page</p>, { path: '/companies', withLayout: true });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    expect(screen.queryByRole('navigation', { name: 'Choisir le produit' })).toBeNull();
  });

  it('est CACHÉ avec Envoi seul', async () => {
    await monter('/envoi/campagnes', FEATURES_ENVOI_SEUL);
    expect(screen.queryByRole('navigation', { name: 'Choisir le produit' })).toBeNull();
  });

  it('est VISIBLE avec les deux produits, avec une pastille carrée par produit', async () => {
    await monter('/companies', FEATURES_LES_DEUX_PRODUITS);
    const selecteur = await screen.findByRole('navigation', { name: 'Choisir le produit' });
    const crm = within(selecteur).getByRole('link', { name: /CRM Pro/ });
    const envoi = within(selecteur).getByRole('link', { name: /Envoi/ });
    expect(crm).toHaveAttribute('aria-current', 'true');
    expect(envoi).not.toHaveAttribute('aria-current');
    // Pastille d'identité : son PROPRE `data-produit`, couleur par jeton.
    const pastilleE = envoi.querySelector('[data-pastille-produit]');
    expect(pastilleE).toHaveAttribute('data-produit', 'envoi');
    expect(pastilleE?.className).toContain('bg-produit');
    expect(pastilleE?.textContent).toBe('E');
    expect(crm.querySelector('[data-pastille-produit]')?.textContent).toBe('C');
  });

  it('passer à Envoi montre les 10 entrées et l’identité turquoise', async () => {
    const vue = await monter('/companies', FEATURES_LES_DEUX_PRODUITS);
    expect(racineDeLaCoquille()).toHaveAttribute('data-produit', 'crm');

    const selecteur = await screen.findByRole('navigation', { name: 'Choisir le produit' });
    await userEvent.click(within(selecteur).getByRole('link', { name: /Envoi/ }));

    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/envoi/campagnes'));
    await waitFor(() => expect(racineDeLaCoquille()).toHaveAttribute('data-produit', 'envoi'));

    const nav = screen.getByRole('navigation', { name: 'Navigation principale' });
    const liens = within(nav).getAllByRole('link');
    expect(liens.map((a) => a.getAttribute('href'))).toEqual(ENTREES_ENVOI.map(([to]) => to));
    for (const [, libelle] of ENTREES_ENVOI) {
      expect(within(nav).getByText(libelle)).toBeInTheDocument();
    }
    // Aucun reste du menu du CRM dans l'espace Envoi.
    expect(within(nav).queryByText('Entreprises')).toBeNull();

    // La teinte d'Envoi est bien celle des maquettes validées.
    const css = readFileSync(
      path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../src/styles/index.css'),
      'utf8',
    );
    const bloc = /\[data-produit=["']envoi["']\]\s*\{([^}]*)\}/.exec(css)?.[1] ?? '';
    expect(bloc).toMatch(/--color-produit:\s*#0b7285/i);
  });

  it('revenir au CRM rend la dernière page ouverte du CRM', async () => {
    const vue = await monter('/companies', FEATURES_LES_DEUX_PRODUITS);
    const selecteur = await screen.findByRole('navigation', { name: 'Choisir le produit' });

    await userEvent.click(within(selecteur).getByRole('link', { name: /Envoi/ }));
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/envoi/campagnes'));

    // Dans Envoi, on ouvre une autre page : elle sera rendue au retour.
    const nav = screen.getByRole('navigation', { name: 'Navigation principale' });
    await userEvent.click(within(nav).getByRole('link', { name: /Domaines/ }));
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/envoi/domaines'));

    const selecteurEnvoi = screen.getByRole('navigation', { name: 'Choisir le produit' });
    await userEvent.click(within(selecteurEnvoi).getByRole('link', { name: /CRM Pro/ }));
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/companies'));
    await waitFor(() => expect(racineDeLaCoquille()).toHaveAttribute('data-produit', 'crm'));

    // Et l'aller suivant vers Envoi rend la dernière page d'Envoi.
    const selecteurCrm = screen.getByRole('navigation', { name: 'Choisir le produit' });
    await userEvent.click(within(selecteurCrm).getByRole('link', { name: /Envoi/ }));
    await waitFor(() => expect(vue.router.state.location.pathname).toBe('/envoi/domaines'));
  });
});
