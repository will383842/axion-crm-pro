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
 *     qui la remplira — ou, une fois ce lot fusionné, SA VRAIE PAGE (titre
 *     attendu, ni « en attente » ni « introuvable ») ;
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
import { TEXTE_EN_PREPARATION } from '@/features/socle/PageEnAttente';
import { FEATURES_LES_DEUX_PRODUITS, ME_FIXTURE, apiUrl, getJson, handlersEnvoi, http, HttpResponse } from '../msw/handlers';
import { server } from '../msw/server';
import { cleRoute, handlers as handlersDemo } from '@/demo/handlers';

/** Les routes `/envoi/…` du faux serveur de la démo. */
const handlersDemoEnvoi = handlersDemo.filter((h) => cleRoute(h).includes('/envoi/'));

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

/**
 * Une URL concrète par route d'Envoi, le lot qui remplit sa page et, dès que
 * ce lot a livré la page, le titre (h1) qu'elle doit afficher. Sans titre, la
 * route doit encore rendre sa page « en attente ».
 *
 * Filet modifié : pourquoi — un lot qui remplace sa page d'attente ajoute ici
 * le titre de la vraie page ; la garde reste aussi stricte (la page doit
 * s'afficher, avec son titre, sans « en attente » ni « introuvable »), et la
 * garde « Sans Envoi → introuvable » couvre toujours TOUTES les routes.
 */
const ROUTES_ENVOI: ReadonlyArray<readonly [string, string, string?]> = [
  ['/envoi/campagnes', 'DM2-CAM', 'Campagnes'],
  ['/envoi/campagnes/calendrier', 'DM2-CAM', 'Calendrier'],
  ['/envoi/campagnes/cmp-01', 'DM2-CAM', 'Organisateurs Rhône-Alpes'],
  ['/envoi/campagnes/nouvelle', 'DM2-ASS', 'Nouvelle campagne'],
  ['/envoi/campagnes/messages', 'DM2-ASS', 'Messages types'],
  ['/envoi/campagnes/messages/msg-1', 'DM2-ASS', 'Une idée pour votre prochain salon'],
  ['/envoi/campagnes/destinataires', 'DM2-CNF', 'Destinataires'],
  ['/envoi/campagnes/resultats', 'DM2-PIL', 'Résultats'],
  ['/envoi/reponses', 'DM2-REP', 'Réponses'],
  ['/envoi/reponses/a-rattacher', 'DM2-REP', 'Réponses à rattacher'],
  ['/envoi/reponses/traitees', 'DM2-REP', 'Réponses traitées'],
  ['/envoi/boites', 'DM2-INF', 'Boîtes d’envoi'],
  ['/envoi/boites/boi-will', 'DM2-INF', 'will@prospection-b.example'],
  ['/envoi/domaines', 'DM2-INF', 'Domaines'],
  ['/envoi/domaines/dom-b', 'DM2-INF', 'prospection-b.example'],
  ['/envoi/serveurs', 'DM2-INF', 'Serveurs et adresses IP'],
  ['/envoi/serveurs/configuration', 'DM2-INF', 'Configuration'],
  ['/envoi/chauffe', 'DM2-INF', 'Chauffe'],
  ['/envoi/desinscriptions', 'DM2-CNF', 'Désinscriptions'],
  ['/envoi/alertes', 'DM2-CNF', 'Alertes d’envoi'],
  ['/envoi/reglages', 'DM2-CNF', 'Réglages d’envoi'],
  ['/envoi/reglages/identite', 'DM2-CNF', 'Identité de l’expéditeur'],
  ['/envoi/reglages/alertes', 'DM2-CNF', 'Alertes et avis'],
  ['/envoi/reglages/regles', 'DM2-CNF', 'Règles et horaires'],
  ['/envoi/reglages/connexion-crm', 'DM2-CNF', 'Connexion au CRM'],
  ['/envoi/sante', 'DM2-PIL', 'Santé des envois'],
  ['/envoi/sante/chercher', 'DM2-PIL', 'Chercher un envoi'],
  ['/envoi/sante/feu-vert', 'DM2-PIL', 'Feu vert'],
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

/**
 * La garde est FERMÉE PAR DÉFAUT (`exigerProduit.ts`) : un état des droits
 * qu'on ne sait pas lire n'ouvre jamais Envoi. Sans ces deux cas, remplacer
 * `return false` par `return true` dans le `catch` laissait tout vert.
 */
describe('Garde fermée par défaut — un état illisible n’ouvre jamais Envoi', () => {
  it('`/config/features` en erreur 500 → « Page introuvable »', async () => {
    server.use(
      http.get(apiUrl('/config/features'), () => HttpResponse.json({ message: 'Erreur serveur' }, { status: 500 })),
      ...handlersEnvoi(),
    );
    await monter('/envoi/campagnes');
    expect(await screen.findByText(/page introuvable/i)).toBeInTheDocument();
    expect(screen.queryByTestId('page-en-attente')).toBeNull();
  });

  it('`produits.envoi: \'oui\'` (valeur inconnue) → « Page introuvable »', async () => {
    server.use(
      getJson('/config/features', { ...FEATURES_LES_DEUX_PRODUITS, produits: { crm: 'actif', envoi: 'oui' } }),
      ...handlersEnvoi(),
    );
    await monter('/envoi/campagnes');
    expect(await screen.findByText(/page introuvable/i)).toBeInTheDocument();
    expect(screen.queryByTestId('page-en-attente')).toBeNull();
  });
});

/** Routes dont le lot n'a pas encore livré la page. */
const ROUTES_EN_ATTENTE = ROUTES_ENVOI.filter(([, , titre]) => titre === undefined).map(([url, lot]) => [url, lot] as const);
/** Routes dont le lot a livré la vraie page, avec le titre attendu. */
const ROUTES_LIVREES = ROUTES_ENVOI.flatMap(([url, lot, titre]) => (titre === undefined ? [] : [[url, lot, titre] as const]));

describe('Envoi actif — chaque route livrée rend sa VRAIE page', () => {
  it.each(ROUTES_LIVREES)('%s → vraie page du lot %s (« %s »)', async (url, _lot, titre) => {
    // Le faux serveur de la démo (le même que celui des e2e) répond aux appels
    // de la page ; droits et compte restent ceux du test (Envoi actif).
    server.use(getJson('/config/features', FEATURES_LES_DEUX_PRODUITS), ...handlersEnvoi(), ...handlersDemoEnvoi);
    await monter(url);
    expect(await screen.findByRole('heading', { level: 1, name: titre }, { timeout: 5_000 })).toBeInTheDocument();
    expect(screen.queryByTestId('page-en-attente')).toBeNull();
    expect(screen.queryByText(/page introuvable/i)).toBeNull();
  });
});

describe('Envoi actif — chaque route rend sa page « en attente »', () => {
  it.each(ROUTES_EN_ATTENTE)('%s → page en attente du lot %s', async (url, lot) => {
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
    // Filet modifié : pourquoi. DM1-COM livre /compte : la page n'est plus « en attente ».
  ])('%s → page en attente du lot %s, texte neutre sans code de lot', async (url, lot) => {
    await monter(url);
    const page = await screen.findByTestId('page-en-attente');
    expect(page).toHaveAttribute('data-lot', lot);
    // Joignable en production par son adresse : aucun code de lot interne affiché.
    expect(page.textContent).toContain(TEXTE_EN_PREPARATION);
    expect(page.textContent).not.toMatch(/\bDM\d|\blot\b/i);
  });

  // Filet modifié : pourquoi. /compte quitte la liste « en attente » (DM1-COM l'a livrée) : cette
  // vérification positive la remplace, aussi stricte. Avec le serveur d'aujourd'hui (pas de
  // `two_factor_method: 'email'`), la vraie page s'affiche sans appeler de route absente : MSW
  // refuse tout appel non servi (`onUnhandledRequest: 'error'`), dont `/compte/appareils`.
  it('/compte → vraie page « Mon compte et sécurité » (DM1-COM), rien qui appelle une route absente', async () => {
    server.use(...handlersDemo.filter((h) => cleRoute(h).includes('/auth/password/change')));
    await monter('/compte');
    expect(await screen.findByRole('heading', { level: 1, name: 'Mon compte et sécurité' }, { timeout: 5_000 })).toBeInTheDocument();
    expect(screen.queryByTestId('page-en-attente')).toBeNull();
    expect(screen.queryByText(/page introuvable/i)).toBeNull();
    expect(await screen.findByText('Raccourcis clavier')).toBeInTheDocument();
    expect(screen.queryByText('Appareils de confiance')).toBeNull();
    expect(screen.queryByText('Codes de secours')).toBeNull();
  });

  it('/desinscription/$jeton n\'est PLUS une route du SPA (servie par le backend, que le SPA ne doit pas masquer)', async () => {
    await monter('/desinscription/jeton-de-test');
    expect(screen.queryByTestId('page-en-attente')).toBeNull();
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
  ['/rgpd/effacements', 'Effacements demandés'],
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
// Filet modifié : pourquoi — empreintes recalées (barre et en-tête) car périmées sur `main`
// depuis les lots de démo DM1/DM2 (constaté en corrigeant le doublon de route
// `GET /companies` qui empêchait ce fichier de se charger), puis la barre à nouveau recalée
// pour la pastille « Demandes RGPD » de L3c-0 (valeurs relevées sur la CI).
// Filet modifié : pourquoi — PR-C : l'entrée « Effacements demandés » (À traiter, avec la pastille déplacée de « Demandes RGPD »)
// et, console ouverte, « Origines des contacts » (Ma base). Empreintes recalées ci-dessous.
const EMPREINTE_BARRE = 'f727efeca75cd33540cf492403a19fd9104f0f5cc4482c878222732634b2400c';
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

/**
 * Même garde, console v2 OUVERTE (`console_v2`, deux univers) : les trois
 * entrées de la console s'ajoutent, rien d'autre. Liste et empreinte de la
 * barre relevées sur `main` (6dddcf1) avant DM0-COQ ; l'en-tête est le même
 * que console fermée.
 */
/** Les trois entrées de la console, à leur place, puis le reste à l'identique. */
const MENU_CONSOLE_OUVERTE: ReadonlyArray<readonly [string, string]> = [
  ['/', 'Tableau de bord'],
  ['/doublons', 'Doublons à vérifier'],
  ['/console/arbitrage', 'Personnes à rattacher'],
  ['/rgpd/effacements', 'Effacements demandés'],
  ['/companies', 'Entreprises'],
  ['/console/contacts', 'Contacts'],
  ['/console/lettre-et-guide', 'Newsletter et guide'],
  ['/origines', 'Origines des contacts'],
  ...MENU_D_AUJOURD_HUI.slice(5),
];
const EMPREINTE_BARRE_CONSOLE_OUVERTE = '1a490440adcf8051690c98d2d7324e2609706f789d39c5a9d690f49211e7ecee';

describe('Sans Envoi, console ouverte — le menu est IDENTIQUE à aujourd’hui (instantané)', () => {
  it('mêmes entrées, même ordre, mêmes libellés ; même HTML de la barre et de l’en-tête', async () => {
    const vue = await renderScreen(<p>Contenu</p>, { path: '/companies', withLayout: true, consoleFeatures: 'open' });
    await screen.findAllByText('Will Test');
    await waitFor(() => expect(vue.queryClient.getQueryState(['crm', 'features'])?.status).toBe('success'));
    const nav = screen.getByRole('navigation', { name: 'Navigation principale' });
    const entrees = [...nav.querySelectorAll('a')].map((a) => [a.getAttribute('href'), a.textContent?.trim()]);
    expect(entrees).toEqual(MENU_CONSOLE_OUVERTE.map(([to, libelle]) => [to, libelle]));
    expect(screen.queryByRole('navigation', { name: 'Choisir le produit' })).toBeNull();
    expect(screen.queryByRole('button', { name: /tout arrêter/i })).toBeNull();
    const barre = document.querySelector('aside')?.outerHTML ?? '';
    const enTete = document.querySelector('header[role="banner"]')?.outerHTML ?? '';
    expect(createHash('sha256').update(barre).digest('hex'), 'Barre latérale modifiée sans Envoi').toBe(EMPREINTE_BARRE_CONSOLE_OUVERTE);
    expect(createHash('sha256').update(enTete).digest('hex'), 'En-tête modifié sans Envoi').toBe(EMPREINTE_EN_TETE);
  });
});
