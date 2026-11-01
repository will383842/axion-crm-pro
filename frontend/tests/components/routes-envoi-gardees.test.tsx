/**
 * DM0-COQ — LES ROUTES D'ENVOI SONT GARDÉES, ET LE MENU DE WILL NE BOUGE PAS.
 *
 * Chaque fusion sur `main` déploie le CRM réel. Tant que la console n'a pas
 * posé `produits.envoi`, RIEN d'Envoi ne doit se voir :
 *  1. chaque route `/envoi/…` répond « Page introuvable » (pas d'indice) ;
 *  2. la barre latérale et l'en-tête sont IDENTIQUES à aujourd'hui — liste des
 *     entrées (lisible) ET empreinte du HTML rendu (stricte) ;
 *  3. Envoi actif mais utilisateur sans accès (`acces_produits.envoi: false`) :
 *     introuvable aussi ;
 *  4. Envoi actif : chaque route rend sa page « en attente », au nom du lot
 *     qui la remplira ;
 *  5. la page publique de désinscription vit HORS coquille.
 *
 * Monte l'arbre de routes RÉEL (`src/app/routeTree.tsx`), comme
 * `ecran-introuvable.test.tsx` : le réécrire ici rendrait la garde vraie par
 * construction.
 */
import { beforeAll, beforeEach, describe, expect, it } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  RouterProvider,
  createMemoryHistory,
  createRouter,
  type AnyRoute,
  type AnyRouter,
} from '@tanstack/react-router';
import { createHash } from 'node:crypto';

import { renderScreen } from '../helpers/renderScreen';
import { FEATURES_LES_DEUX_PRODUITS, ME_FIXTURE, getJson, handlersEnvoi } from '../msw/handlers';
import { server } from '../msw/server';

let arbre: AnyRoute;

beforeAll(async () => {
  // `maplibre-gl` appelle `URL.createObjectURL` au chargement (cf.
  // `ecran-introuvable.test.tsx`) : lacune de jsdom, pas défaut du produit.
  if (typeof window.URL.createObjectURL !== 'function') {
    Object.defineProperty(window.URL, 'createObjectURL', { writable: true, configurable: true, value: () => 'blob:bouchon-jsdom' });
    Object.defineProperty(window.URL, 'revokeObjectURL', { writable: true, configurable: true, value: () => {} });
  }
  const module = await import('@/app/routeTree');
  arbre = module.routeTree as AnyRoute;
}, 30_000);

beforeEach(() => {
  window.sessionStorage.clear();
});

async function monter(url: string) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
  const router: AnyRouter = createRouter({
    routeTree: arbre,
    history: createMemoryHistory({ initialEntries: [url] }),
    context: { queryClient },
  });
  await router.load();
  const vue = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );
  return { ...vue, router };
}

/** Une URL concrète par route d'Envoi, et le lot qui remplira sa page. */
const ROUTES_ENVOI: ReadonlyArray<readonly [string, string]> = [
  ['/envoi/campagnes', 'DM2-CAM'],
  ['/envoi/campagnes/calendrier', 'DM2-CAM'],
  ['/envoi/campagnes/c-1', 'DM2-CAM'],
  ['/envoi/campagnes/nouvelle', 'DM2-ASS'],
  ['/envoi/campagnes/messages', 'DM2-ASS'],
  ['/envoi/campagnes/messages/m-1', 'DM2-ASS'],
  ['/envoi/campagnes/resultats', 'DM2-PIL'],
  ['/envoi/campagnes/destinataires', 'DM2-CNF'],
  ['/envoi/reponses', 'DM2-REP'],
  ['/envoi/reponses/a-rattacher', 'DM2-REP'],
  ['/envoi/reponses/traitees', 'DM2-REP'],
  ['/envoi/boites', 'DM2-INF'],
  ['/envoi/boites/b-1', 'DM2-INF'],
  ['/envoi/domaines', 'DM2-INF'],
  ['/envoi/domaines/d-1', 'DM2-INF'],
  ['/envoi/serveurs', 'DM2-INF'],
  ['/envoi/serveurs/configuration', 'DM2-INF'],
  ['/envoi/chauffe', 'DM2-INF'],
  ['/envoi/sante', 'DM2-PIL'],
  ['/envoi/sante/chercher', 'DM2-PIL'],
  ['/envoi/sante/feu-vert', 'DM2-PIL'],
  ['/envoi/desinscriptions', 'DM2-CNF'],
  ['/envoi/alertes', 'DM2-CNF'],
  ['/envoi/reglages', 'DM2-CNF'],
  ['/envoi/reglages/identite', 'DM2-CNF'],
  ['/envoi/reglages/alertes', 'DM2-CNF'],
  ['/envoi/reglages/regles', 'DM2-CNF'],
  ['/envoi/reglages/connexion-crm', 'DM2-CNF'],
];

describe('Sans Envoi (défaut du serveur) — chaque route d’Envoi est introuvable', () => {
  it.each([['/envoi'], ...ROUTES_ENVOI.map(([url]) => [url])])('%s → « Page introuvable »', async (url) => {
    await monter(url);
    expect(await screen.findByText(/page introuvable/i)).toBeInTheDocument();
    expect(screen.queryByText(/en attente/i)).toBeNull();
  });
});

describe('Envoi actif mais utilisateur SANS accès — introuvable aussi', () => {
  it('/envoi/campagnes → « Page introuvable »', async () => {
    server.use(
      getJson('/config/features', FEATURES_LES_DEUX_PRODUITS),
      getJson('/auth/me', { ...ME_FIXTURE, acces_produits: { crm: true, envoi: false } }),
      ...handlersEnvoi(),
    );
    await monter('/envoi/campagnes');
    expect(await screen.findByText(/page introuvable/i)).toBeInTheDocument();
  });
});

describe('Envoi actif — chaque route rend sa page « en attente »', () => {
  it.each(ROUTES_ENVOI)('%s → page en attente du lot %s', async (url, lot) => {
    server.use(getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlersEnvoi());
    await monter(url);
    const page = await screen.findByTestId('page-en-attente');
    expect(page).toHaveAttribute('data-lot', lot);
    expect(page.textContent).toContain(lot);
    expect(screen.queryByText(/page introuvable/i)).toBeNull();
  });

  it('/envoi redirige vers /envoi/campagnes', async () => {
    server.use(getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlersEnvoi());
    const { router } = await monter('/envoi');
    await waitFor(() => expect(router.state.location.pathname).toBe('/envoi/campagnes'));
  });
});

describe('Routes du CRM et des écrans communs créées « en attente »', () => {
  it.each([
    ['/taches', 'DM1-ACC'],
    ['/affaires', 'DM1-ACC'],
    ['/compte', 'DM1-COM'],
  ])('%s → page en attente du lot %s', async (url, lot) => {
    await monter(url);
    expect(await screen.findByTestId('page-en-attente')).toHaveAttribute('data-lot', lot);
  });

  it('/desinscription/$jeton est publique et HORS coquille', async () => {
    await monter('/desinscription/jeton-de-test');
    expect(await screen.findByTestId('page-en-attente')).toHaveAttribute('data-lot', 'DM2-CNF');
    expect(screen.queryByRole('complementary', { name: 'Navigation latérale' })).toBeNull();
    expect(document.querySelector('[data-coquille]')).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// Le menu de Will, IDENTIQUE à aujourd'hui quand Envoi est absent
// ---------------------------------------------------------------------------

/**
 * Le menu relevé sur `main` le 01/11/2026 (avant DM0-COQ), drapeau console
 * fermé — la configuration servie par défaut. Une entrée de plus, de moins ou
 * renommée fait rougir.
 */
const MENU_D_AUJOURD_HUI: ReadonlyArray<readonly [string, string]> = [
  ['/', 'Tableau de bord'],
  ['/doublons', 'Doublons à vérifier'],
  ['/companies', 'Entreprises'],
  ['/contacts', 'Contacts'],
  ['/media', 'Médias'],
  ['/journalists', 'Journalistes'],
  ['/presse/envois', 'Communiqués envoyés'],
  ['/federations', 'Fédérations et ordres'],
  ['/evenements', 'Événements'],
  ['/audiences', 'Audiences'],
  ['/listes', 'Listes'],
  ['/collectes', 'Collectes'],
  ['/coverage', 'Carte de France'],
  ['/settings', 'Paramètres'],
  ['/users', 'Utilisateurs'],
  ['/tags', 'Étiquettes'],
  ['/rgpd/requests', 'Demandes RGPD'],
  ['/international/roumanie', 'Entreprises en Roumanie'],
  ['/scraper-runs', 'Historique des collectes'],
  ['/admin/observability', 'Santé du système'],
  ['/llm/router', 'Moteurs d’IA'],
  ['/llm/proxy-providers', 'Serveurs relais'],
  ['/llm/rotations', 'Rotation des accès'],
  ['/rgpd/ai-act', 'Registre de l’IA'],
  ['/audit-logs', 'Journal des actions'],
];

/**
 * Empreintes SHA-256 du HTML de la barre latérale (`<aside>`) et de l'en-tête
 * (`<header role="banner">`), rendus sur `/companies` avec les handlers par
 * défaut, relevées sur `main` (6dddcf1) avant DM0-COQ. Sans Envoi, DM0-COQ ne
 * doit RIEN changer à ces deux blocs — pas une classe, pas un attribut.
 *
 * ⚠️ Si un lot ultérieur change volontairement la barre ou l'en-tête du CRM,
 * il met ces empreintes à jour et l'écrit dans sa PR (« Filet modifié :
 * pourquoi »).
 */
const EMPREINTE_BARRE = 'b838a7b756cac09511c8519deca111fa840e06c894718604f69462153ac98845';
const EMPREINTE_EN_TETE = '86c431569957790ceb75e26e9bea78eca245a5627e382c0088599dc3c1c56645';

describe('Sans Envoi — le menu est IDENTIQUE à aujourd’hui (instantané)', () => {
  it('mêmes entrées, même ordre, mêmes libellés', async () => {
    const vue = await renderScreen(<p>Contenu</p>, { path: '/companies', withLayout: true });
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    const nav = screen.getByRole('navigation', { name: 'Navigation principale' });
    const entrees = [...nav.querySelectorAll('a')].map((a) => [a.getAttribute('href'), a.textContent?.trim()]);
    expect(entrees).toEqual(MENU_D_AUJOURD_HUI.map(([to, libelle]) => [to, libelle]));
    expect(screen.queryByRole('navigation', { name: 'Choisir le produit' })).toBeNull();
    expect(screen.queryByRole('button', { name: /tout arrêter/i })).toBeNull();
  });

  it('la barre latérale et l’en-tête rendent le MÊME HTML qu’avant DM0-COQ', async () => {
    const vue = await renderScreen(<p>Contenu</p>, { path: '/companies', withLayout: true });
    await screen.findAllByText('Will Test');
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    const barre = document.querySelector('aside')?.outerHTML ?? '';
    const enTete = document.querySelector('header[role="banner"]')?.outerHTML ?? '';
    expect(createHash('sha256').update(barre).digest('hex'), 'Barre latérale modifiée sans Envoi').toBe(EMPREINTE_BARRE);
    expect(createHash('sha256').update(enTete).digest('hex'), 'En-tête modifié sans Envoi').toBe(EMPREINTE_EN_TETE);
  });
});
