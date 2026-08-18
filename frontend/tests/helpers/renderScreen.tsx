/**
 * `renderScreen` — monter un ÉCRAN DE ROUTE avec tout son environnement.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ DÉCISION 1 — un VRAI routeur en mémoire, pas `vi.mock('@tanstack/…')`.   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Le motif en place dans le dépôt (`tests/components/ContactsHubPage.test.tsx:25`)
 * remplaçait le routeur par `Link: ({children}) => <span>{children}</span>`.
 * Trois choses disparaissent alors du test :
 *
 *  1. `useParams({ from: '/layout/console/personnes/$personKey' })` — un mock
 *     doit RÉINVENTER les paramètres, donc le test valide la valeur que le test
 *     s'est donnée, jamais l'extraction depuis l'URL. Une faute de frappe dans
 *     le `from` reste invisible.
 *  2. `useNavigate()` — `navigate({ to: '/2fa' })` devient un `vi.fn()`. On
 *     assure « la fonction a été appelée », pas « on a atterri ». Un `to` qui
 *     ne correspond à aucune route passe.
 *  3. `Link` — le `to` et les `params` ne sont plus résolus : un lien cassé
 *     (`/console/personne/$key` au lieu de `/console/personnes/$personKey`)
 *     rend exactement pareil.
 *
 * Un mock de routeur qui ne route pas rend tout test de « parcours » creux.
 * On monte donc un VRAI routeur (`createMemoryHistory` + `RouterProvider`) —
 * `router.state.location.pathname` devient une assertion, et `useParams`
 * extrait pour de bon.
 *
 * Ce qu'on N'a PAS fait : importer `src/app/routeTree.tsx`. Il charge les 37
 * modules d'écran (maplibre-gl, react-joyride, pusher…) pour en tester un —
 * plusieurs secondes par fichier, et un écran cassé ferait rougir les tests de
 * tous les autres. On reconstruit à la place un arbre MINIMAL dont les
 * identifiants de route sont IDENTIQUES à ceux du vrai (`id: 'layout'` sur la
 * coquille, mêmes chemins) : c'est ce qui rend `useParams({ from })` fidèle.
 * ⚠️ Corollaire à connaître : si quelqu'un renomme un chemin dans
 * `routeTree.tsx`, ces tests ne le verront pas. C'est le rôle des tests
 * Playwright (`tests/e2e/navigation.spec.ts`) de couvrir l'arbre réel.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ DÉCISION 2 — l'écran est monté SEUL par défaut, pas sous `RootLayout`.   ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * `RootLayout` tire la barre latérale (9 sections), l'en-tête, la recherche
 * globale, `OnboardingTour`, et un `useQuery(['auth','me'])`. Monté par défaut,
 * il ferait échouer les recherches par rôle : « Contacts » existe dans la
 * navigation ET dans le titre de la page, donc `getByRole('link', {name})`
 * trouverait deux éléments et le test rougirait pour une raison qui n'a rien à
 * voir avec l'écran.
 *
 * `withLayout: true` reste disponible pour ce qui se joue précisément dans la
 * coquille (le fil d'Ariane, l'entrée de menu active). `/auth/me` est déjà
 * simulé par défaut (`tests/msw/handlers.ts`) et le temps réel est coupé par
 * `VITE_ECHO_DISABLED` dans `tests/setup.ts` — ni l'un ni l'autre n'a besoin
 * d'être répété dans un test.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ RECETTE — ajouter un écran (voir aussi `tests/README.md`)                ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *   const view = await renderScreen(<MonEcran />, {
 *     path: '/mon-chemin/$id',            // tel quel dans routeTree.tsx
 *     url: '/mon-chemin/42',              // l'URL réellement visitée
 *     handlers: [getJson('/mon-endpoint', FIXTURE)],
 *     landingRoutes: ['/ailleurs'],       // destinations observables
 *   });
 *   expect(await screen.findByRole('heading', { name: 'Mon écran' })).toBeVisible();
 */
import type { ReactElement, ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import {
  Outlet,
  RouterProvider,
  createMemoryHistory,
  createRootRoute,
  createRoute,
  createRouter,
  type AnyRoute,
  type AnyRouter,
} from '@tanstack/react-router';
import { render, type RenderResult } from '@testing-library/react';
import type * as ApiModule from '@/lib/api';
import type { HttpHandler } from 'msw';
import { vi } from 'vitest';

import { RootLayout } from '@/app/RootLayout';
import { CONSOLE_FEATURES_KEY } from '@/features/crm-console/useConsoleFeatures';
import { server } from '../msw/server';
import { CONSOLE_FEATURES_CLOSED, CONSOLE_FEATURES_OPEN, getJson } from '../msw/handlers';

// L'i18n RÉEL — pas un `t: (k) => k`. Les écrans affichent « Adresse e-mail »
// (fr.json), et c'est ce libellé que l'utilisateur cherche des yeux : un test
// qui interrogerait `auth.login.email` passerait alors que la traduction
// manque. L'initialisation est un effet de bord d'import, idempotente.
import i18n from '@/lib/i18n';

export interface LandingRoute {
  path: string;
  /** Le contenu rendu à cette adresse. Omis → une balise `data-testid="landing"`. */
  element?: ReactNode;
  /** Hors coquille `layout`. Défaut : vrai pour `/login` et `/2fa`. */
  outsideLayout?: boolean;
}

export interface RenderScreenOptions {
  /**
   * Le chemin de la route, COPIÉ depuis `src/app/routeTree.tsx`
   * (ex. `/console/personnes/$personKey`). Défaut : `/`.
   */
  path?: string;
  /**
   * L'URL effectivement visitée, paramètres résolus
   * (ex. `/console/personnes/p-42`). Défaut : `path` tel quel.
   */
  url?: string;
  /** Handlers MSW propres à ce cas ; empilés au-dessus des handlers par défaut. */
  handlers?: HttpHandler[];
  /**
   * Monter l'écran sous `RootLayout` (barre latérale + en-tête). Défaut : `false`.
   * N'activer que si le test porte SUR la coquille.
   */
  withLayout?: boolean;
  /**
   * Route HORS coquille — comme `/login`, `/2fa` dans `routeTree.tsx`.
   * Défaut : `false` (l'écran est un enfant de la route `layout`).
   */
  outsideLayout?: boolean;
  /**
   * Pré-remplit le cache du drapeau console pour court-circuiter `ConsoleGate`.
   *  - `'open'`   : console v2 ouverte, univers business.
   *  - `'vivier'` : console ouverte + accès vivier.
   *  - `'closed'` : drapeau connu et FERMÉ (le gardien affiche son message).
   *  - `undefined`: on ne pré-remplit RIEN — `/config/features` est appelé pour
   *                 de vrai (répond fermé par défaut, cf. handlers).
   */
  consoleFeatures?: 'open' | 'vivier' | 'closed';
  /**
   * Destinations supplémentaires enregistrées dans l'arbre. Sert à OBSERVER une
   * navigation : après le clic, le test assure `screen.getByTestId('landing')`
   * ou `router.state.location.pathname`. Indispensable dès qu'un écran fait
   * `navigate({ to: … })` ou porte un `Link` — sans la route, TanStack Router
   * tombe sur « pas de correspondance » et le clic ne prouve rien.
   *
   * Deux formes :
   *  - `'/audiences'` → une balise repérable `data-testid="landing"` ;
   *  - `{ path: '/console/personnes/$personKey', element: <PersonTimelinePage /> }`
   *    → le VRAI écran de destination, pour un parcours qui traverse deux
   *    écrans (c'est ainsi qu'on vérifie qu'un paramètre de route arrive
   *    jusqu'à la requête).
   *
   * `outsideLayout` place la destination hors coquille (cas de `/login`, `/2fa`
   * dans `routeTree.tsx`) ; par défaut, les chemins commençant par `/login` ou
   * `/2fa` le sont automatiquement, comme dans l'arbre réel.
   */
  landingRoutes?: ReadonlyArray<string | LandingRoute>;
  /** `QueryClient` sur mesure. Par défaut : `retry: false`, `gcTime: 0`. */
  queryClient?: QueryClient;
}

export interface ScreenView extends RenderResult {
  /**
   * Le routeur réel — `view.router.state.location.pathname` est une assertion.
   *
   * Typé `AnyRouter` à dessein : l'arbre est construit à la volée, ses types
   * littéraux (`'/'`, `'__root__'`…) diffèrent à chaque appel, et un type exact
   * ne servirait qu'à faire échouer `tsc` sur des chemins que le test connaît.
   */
  router: AnyRouter;
  queryClient: QueryClient;
}

export function createTestQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { retry: false, gcTime: 0, staleTime: 0 },
      mutations: { retry: false },
    },
  });
}

const FEATURES_BY_NAME = {
  open: CONSOLE_FEATURES_OPEN,
  vivier: { console_v2: true, universes: { business: true, vivier: true } },
  closed: CONSOLE_FEATURES_CLOSED,
} as const;

/** Marqueur rendu par les routes d'atterrissage — cf. `landingRoutes`. */
function Landing({ path }: { path: string }): ReactElement {
  return (
    <div data-testid="landing" data-path={path}>
      Écran {path}
    </div>
  );
}

function Passthrough(): ReactElement {
  return <Outlet />;
}

/**
 * Monte un écran de route. `await` obligatoire : le routeur résout la
 * correspondance avant le premier rendu, sinon `RouterProvider` rend le vide au
 * premier tick et une assertion synchrone échouerait sans raison.
 */
export async function renderScreen(
  element: ReactNode,
  options: RenderScreenOptions = {},
): Promise<ScreenView> {
  const {
    path = '/',
    url = path,
    handlers = [],
    withLayout = false,
    outsideLayout = false,
    consoleFeatures,
    landingRoutes = [],
    queryClient = createTestQueryClient(),
  } = options;

  // ⚠️ `LanguageDetector` lit `navigator.language`, que jsdom fixe à `en-US` :
  // sans cette ligne, les écrans se rendent en ANGLAIS et tous les libellés
  // attendus sont faux — un rouge qui n'a rien à voir avec l'écran. Le produit
  // est français (fr = `fallbackLng`), on épingle donc le français.
  if (i18n.language !== 'fr') await i18n.changeLanguage('fr');

  if (handlers.length > 0) server.use(...handlers);

  if (consoleFeatures !== undefined) {
    const features = FEATURES_BY_NAME[consoleFeatures];
    queryClient.setQueryData(CONSOLE_FEATURES_KEY, features);
    // Le cache seul ne suffit pas : `staleTime` écoulé, React Query REFETCHE
    // en arrière-plan et la vraie réponse (fermée) écraserait la valeur semée.
    // On aligne donc les deux couches, comme en production.
    server.use(getJson('/config/features', features));
  }

  const rootRoute = createRootRoute({ component: Passthrough });

  // ⚠️ `id: 'layout'` — c'est ce qui donne aux enfants un identifiant complet
  // `/layout/<chemin>`, exactement comme dans `src/app/routeTree.tsx`. C'est
  // cet identifiant que `PersonTimelinePage` passe à `useParams({ from })`.
  const layoutRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: 'layout',
    component: withLayout ? RootLayout : Passthrough,
  });

  const parentOfScreen = outsideLayout ? rootRoute : layoutRoute;

  const screenRoute = createRoute({
    getParentRoute: () => parentOfScreen as AnyRoute,
    path,
    component: () => <>{element}</>,
  });

  const normalized: LandingRoute[] = landingRoutes
    .map((entry) => (typeof entry === 'string' ? { path: entry } : entry))
    .filter((entry) => entry.path !== path);

  const rootLandings: AnyRoute[] = [];
  const layoutLandings: AnyRoute[] = [];

  for (const entry of normalized) {
    const outside = entry.outsideLayout ?? (entry.path.startsWith('/login') || entry.path.startsWith('/2fa'));
    const parent = outside ? rootRoute : layoutRoute;
    const content = entry.element;
    const route = createRoute({
      getParentRoute: () => parent as AnyRoute,
      path: entry.path,
      component: () => (content === undefined ? <Landing path={entry.path} /> : <>{content}</>),
    });
    (outside ? rootLandings : layoutLandings).push(route);
  }

  const enfantsLayout: AnyRoute[] = [
    ...(outsideLayout ? [] : [screenRoute as AnyRoute]),
    ...layoutLandings,
  ];
  const enfantsRacine: AnyRoute[] = [
    ...(outsideLayout ? [screenRoute as AnyRoute] : []),
    ...rootLandings,
    layoutRoute.addChildren(enfantsLayout) as AnyRoute,
  ];
  const routeTree = rootRoute.addChildren(enfantsRacine);

  const router: AnyRouter = createRouter({
    routeTree,
    history: createMemoryHistory({ initialEntries: [url] }),
    defaultPendingMs: 0,
    // Un écran qui lève ne doit pas être avalé par une frontière d'erreur muette :
    // on veut le rouge, avec la pile, dans la sortie de vitest.
    defaultErrorComponent: ({ error }: { error: Error }) => {
      throw error;
    },
  });

  await router.load();

  const result = render(
    <QueryClientProvider client={queryClient}>
      <RouterProvider router={router} />
    </QueryClientProvider>,
  );

  return Object.assign(result, { router, queryClient });
}

/**
 * Rembobine le drapeau de module `csrfFetched` (`src/lib/api.ts:13`).
 *
 * ⚠️ N'a d'effet que sur les modules importés APRÈS l'appel : il faut donc
 * ré-importer dynamiquement l'écran ET `@/lib/api`. À n'utiliser que pour
 * assurer explicitement la demande de cookie CSRF ; partout ailleurs, laisser
 * le drapeau tranquille (cf. la note en pied de `tests/setup.ts`).
 */
export async function resetCsrfFlag(): Promise<typeof ApiModule> {
  vi.resetModules();
  return import('@/lib/api');
}

export { CONSOLE_FEATURES_KEY };
