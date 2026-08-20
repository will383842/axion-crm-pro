/**
 * P6-UI-005 (S1) / D24-008 — « `ErrorBoundary` est ecrit, exporte, et MONTE
 * NULLE PART ». Cette garde mesure la consequence, pas l'intention.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ CE QUE LE CODE DISAIT AVANT LA REPARATION (mesure, pas supposition)      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 *  1. `src/components/ui/ErrorBoundary.tsx` existe et est reexporte par
 *     `src/components/ui/index.ts:33`. `grep -rn ErrorBoundary src/` ne
 *     retournait QUE ces deux lignes plus sa propre declaration : AUCUN site de
 *     montage. 3 occurrences, 0 montage.
 *
 *  2. ⚠️ L'AUDIT SE TROMPE SUR LA CONSEQUENCE. Il annonce « page blanche sur
 *     toute exception de rendu ». MESURE (sortie ROUGE de cette garde, avant
 *     reparation) : le contenu de la page etait exactement
 *
 *         « Something went wrong!Hide ErrorExplosion de rendu simulee P6-UI-005 »
 *
 *     Ce n'est pas une page blanche, c'est l'ecran par defaut de la librairie.
 *     `RouterProvider` pose un filet GLOBAL sans qu'on le demande —
 *     `@tanstack/react-router@1.170.4`, `dist/esm/Matches.js:36-44` :
 *
 *         router.options.disableGlobalCatchBoundary ? matchComponent : jsx(CatchBoundary, {
 *           getResetKey: () => resetKey,
 *           errorComponent: ErrorComponent,
 *           ...
 *
 *     Le defaut reste grave, mais pour TROIS raisons differentes de celle
 *     ecrite :
 *       - le message est en ANGLAIS dans un produit francais ;
 *       - ce filet enveloppe TOUS les matches : la coquille entiere disparait
 *         (`#main`, barre laterale, en-tete). Plus de navigation possible ;
 *       - le message brut de l'exception est offert derriere « Show Error »
 *         (`CatchBoundary.js:50-93`).
 *
 *     Le piege de lecture : `Match.js:76`
 *     (`routeErrorComponent ? CatchBoundary : SafeFragment`) laisse croire
 *     qu'AUCUNE frontiere n'est posee quand on ne configure rien. C'est vrai AU
 *     NIVEAU DU MATCH, et faux au niveau global. Conclure « page blanche » en
 *     s'arretant a cette ligne est exactement l'erreur commise.
 *
 *     La vraie page blanche existe quand meme, mais ailleurs : pour ce qui
 *     explose HORS de `RouterProvider` (cf. le cas `AppRoot` plus bas).
 *
 *  3. `tests/e2e/console-locale.spec.ts:379` assurait deja
 *     `not.toContainText('Une erreur est survenue.')`. Sans montage, ce texte
 *     ne pouvait apparaitre NULLE PART : assertion vraie par construction.
 *     Elle ne devient une vraie assertion qu'apres cette reparation.
 *
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║ POURQUOI PAS `renderScreen` (tests/helpers/renderScreen.tsx)             ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Ce helper pose `defaultErrorComponent: ({ error }) => { throw error }`
 * (renderScreen.tsx:290) — deliberement, pour que le rouge d'un ecran casse
 * remonte dans vitest. Mais c'est justement l'option que la production N'A PAS.
 * S'en servir ici reviendrait a tester une composition qui n'existe pas.
 * On reconstruit donc le routeur EXACTEMENT comme `src/main.tsx` :
 * `createRouter({ routeTree, defaultPreload: 'intent' })`, sans
 * `defaultErrorComponent`. Toute divergence avec main.tsx invaliderait la garde.
 */
import type { ReactElement } from 'react';
import { afterEach, beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { render, waitFor } from '@testing-library/react';
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

import { AppRoot } from '@/app/AppRoot';
import { RootLayout } from '@/app/RootLayout';
import i18n from '@/lib/i18n';

/**
 * Le composant de `rootRoute`, PRIS DANS LE VRAI ARBRE DE ROUTES.
 *
 * On ne le reecrit pas dans le test : sinon la garde verifierait une
 * composition inventee ici, et retirer la frontiere de `src/app/routeTree.tsx`
 * ne la ferait pas rougir — precisement le defaut « assertion vraie par
 * construction » que ce lot repare.
 *
 * ⚠️ IMPORT DYNAMIQUE, ET NON STATIQUE. `routeTree` charge les ~37 modules
 * d'ecran, dont `CoveragePage` -> `maplibre-gl`, qui appelle
 * `window.URL.createObjectURL` AU CHARGEMENT DU MODULE
 * (`maplibre-gl/dist/maplibre-gl.js:34`). jsdom ne fournit pas cette fonction :
 * en import statique, le fichier de test entier echouait a la collecte avec
 * « window.URL.createObjectURL is not a function », AVANT le moindre cas. Le
 * bouchon est pose ci-dessous, puis l'arbre est importe. C'est une lacune de
 * jsdom, pas un defaut du produit — on ne bouchonne rien du code teste.
 *
 * Le bouchon est pose ICI et non dans `tests/setup.ts` : ce dernier est partage
 * par toute la suite, et l'elargir pour un seul fichier ferait porter a tous un
 * environnement plus permissif.
 */
type ComposantDeRoute = NonNullable<NonNullable<Parameters<typeof createRootRoute>[0]>['component']>;
let COMPOSANT_RACINE_DE_PRODUCTION: ComposantDeRoute;

beforeAll(async () => {
  if (typeof window.URL.createObjectURL !== 'function') {
    Object.defineProperty(window.URL, 'createObjectURL', {
      writable: true,
      configurable: true,
      value: () => 'blob:bouchon-jsdom',
    });
    Object.defineProperty(window.URL, 'revokeObjectURL', {
      writable: true,
      configurable: true,
      value: () => {},
    });
  }
  const { rootRoute } = await import('@/app/routeTree');
  const composant = rootRoute.options.component;

  // Sans ce controle, un `routeTree` qui ne declarerait plus de composant de
  // racine rendrait `racine: 'reelle'` equivalent a `racine: 'nue'` — et toute
  // la section « site jumeau » passerait au vert sans rien verifier. On ECHOUE
  // en le disant, on ne se rabat pas silencieusement sur un `Outlet`.
  if (typeof composant !== 'function') {
    throw new Error(
      "rootRoute (src/app/routeTree.tsx) ne declare plus de composant : la garde du site jumeau ne mesurerait plus rien.",
    );
  }
  COMPOSANT_RACINE_DE_PRODUCTION = composant;
});

/**
 * ⚠️ Sous-chaines SANS LETTRE ACCENTUEE, a dessein.
 *
 * Le libelle produit est « Une erreur est survenue. » — pas d'accent, on peut le
 * chercher tel quel. `ErrorBoundary.tsx` peut evoluer, mais si quelqu'un
 * remplace ce libelle par un autre, la garde doit rougir : c'est ce texte-la que
 * `tests/e2e/console-locale.spec.ts:380` surveille en bout de chaine.
 */
const LIBELLE_FRONTIERE = 'Une erreur est survenue';
const LIBELLE_RECHARGER = 'Recharger la page';
/** L'ecran par defaut de TanStack Router — celui qu'on ne veut plus voir. */
const LIBELLE_LIBRAIRIE = 'Something went wrong!';
const MARQUEUR_ECRAN_SAIN = 'Contenu de l ecran sain';
const MESSAGE_EXPLOSION = 'Explosion de rendu simulee P6-UI-005';

/** L'ecran qui explose AU RENDU — pas dans un effet, pas dans un handler. */
function EcranQuiExplose(): ReactElement {
  throw new Error(MESSAGE_EXPLOSION);
}

function EcranSain(): ReactElement {
  return <p>{MARQUEUR_ECRAN_SAIN}</p>;
}

function Passthrough(): ReactElement {
  return <Outlet />;
}

interface OptionsMontage {
  /**
   * `'reelle'` utilise le composant de `rootRoute` TEL QU'IL EST EN PRODUCTION
   * (`src/app/routeTree.tsx`). `'nue'` le remplace par un simple `Outlet` :
   * c'est l'etat d'AVANT reparation, conserve comme temoin.
   */
  racine: 'reelle' | 'nue';
  /**
   * `'reelle'` monte le VRAI `RootLayout` (barre laterale, en-tete, `#main`).
   * `'nue'` le remplace par un simple `Outlet`.
   */
  coquille: 'reelle' | 'nue';
  /** L'ecran monte a `/`. */
  ecran: () => ReactElement;
  /** Ecran monte a `/ailleurs`, pour verifier le rearmement apres navigation. */
  ecranAilleurs?: () => ReactElement;
  url?: string;
}

interface Montage {
  container: HTMLElement;
  router: AnyRouter;
  /**
   * L'historique en memoire, RENVOYE PAR LE HARNAIS et non relu sur le routeur.
   * `AnyRouter.history` est type `any` : passer par lui faisait rougir
   * `@typescript-eslint/no-unsafe-call`, et surtout privait le test de tout
   * controle de type sur la navigation.
   */
  historique: ReturnType<typeof createMemoryHistory>;
  /**
   * L'exception RELANCEE hors de `render()`, s'il y en a une. React 19 relance
   * synchroniquement une erreur de rendu qu'aucune frontiere n'attrape ; en
   * production, cela veut dire que `createRoot().render()` leve au chargement du
   * module et que le document reste vide.
   */
  erreurRelancee: unknown;
}

async function monter(options: OptionsMontage): Promise<Montage> {
  const { racine, coquille, ecran, ecranAilleurs, url = '/' } = options;

  // jsdom fixe `navigator.language` a `en-US` : sans cette ligne les libelles
  // de la coquille partent en anglais (cf. renderScreen.tsx:222).
  if (i18n.language !== 'fr') await i18n.changeLanguage('fr');

  const rootRoute = createRootRoute({
    component: racine === 'reelle' ? COMPOSANT_RACINE_DE_PRODUCTION : Passthrough,
  });
  const layoutRoute = createRoute({
    getParentRoute: () => rootRoute,
    id: 'layout',
    component: coquille === 'reelle' ? RootLayout : Passthrough,
  });
  const ecranRoute = createRoute({
    getParentRoute: () => layoutRoute,
    path: '/',
    component: ecran,
  });
  const enfants: AnyRoute[] = [ecranRoute as AnyRoute];
  if (ecranAilleurs) {
    enfants.push(
      createRoute({
        getParentRoute: () => layoutRoute,
        path: '/ailleurs',
        component: ecranAilleurs,
      }) as AnyRoute,
    );
  }
  const routeTree = rootRoute.addChildren([layoutRoute.addChildren(enfants) as AnyRoute]);

  // ⚠️ AUCUN `defaultErrorComponent`, AUCUN `errorComponent` : c'est la
  // configuration LITTERALE de `src/main.tsx:28-32`. La garde ne vaut que tant
  // que cette ligne reste le miroir de celle de production.
  const historique = createMemoryHistory({ initialEntries: [url] });
  const router: AnyRouter = createRouter({
    routeTree,
    history: historique,
    defaultPreload: 'intent',
  });

  await router.load();

  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false, gcTime: 0 } },
  });

  // Conteneur cree a la main : si `render()` leve, testing-library ne rend
  // aucun resultat, et on n'aurait plus rien a inspecter. Ici on garde le
  // conteneur — c'est lui la preuve de la page blanche.
  const container = document.createElement('div');
  document.body.appendChild(container);

  let erreurRelancee: unknown = null;
  try {
    render(
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
      { container },
    );
  } catch (err) {
    erreurRelancee = err;
  }

  return { container, router, historique, erreurRelancee };
}

/**
 * React ecrit l'exception attrapee sur `console.error`, et `componentDidCatch`
 * de `ErrorBoundary` en rajoute une. On les tait pour garder la sortie lisible,
 * MAIS on garde l'espion : « la frontiere a journalise » est une assertion.
 */
let espionConsole: ReturnType<typeof vi.spyOn>;

beforeEach(() => {
  espionConsole = vi.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
  espionConsole.mockRestore();
});

// ---------------------------------------------------------------------------
// 1. TEMOIN — l'instrument de mesure
// ---------------------------------------------------------------------------

describe('TEMOIN — ce que mesure cette garde', () => {
  it('sans nos frontieres, l utilisateur tombe sur l ecran ANGLAIS de la librairie', async () => {
    // La composition d'AVANT reparation, reproduite a l'identique : ni racine
    // ni coquille ne portent de frontiere. C'est ce cas qui a produit la sortie
    // ROUGE ayant servi a corriger l'enonce de l'audit.
    const { container, erreurRelancee } = await monter({
      racine: 'nue',
      coquille: 'nue',
      ecran: EcranQuiExplose,
    });

    // Ce n'est PAS une page blanche : le filet global de TanStack Router
    // (`Matches.js:36`) a repondu, en anglais.
    expect(container.textContent).toContain(LIBELLE_LIBRAIRIE);
    expect(container.textContent).not.toContain(LIBELLE_FRONTIERE);

    // Et il divulgue le message brut de l'exception a l'utilisateur final.
    expect(container.textContent).toContain(MESSAGE_EXPLOSION);

    // Rien ne s'est echappe : c'est bien la librairie qui a attrape, pas nous.
    expect(erreurRelancee).toBeNull();
  });

  it('la meme mesure voit un ecran sain — donc le harnais monte pour de vrai', async () => {
    // Sans ce cas, les `toContain` ci-dessus pourraient etre lus comme « le
    // harnais ne monte rien ». Il monte : voici le contenu.
    const { container, erreurRelancee } = await monter({
      racine: 'nue',
      coquille: 'nue',
      ecran: EcranSain,
    });

    expect(container.textContent).toContain(MARQUEUR_ECRAN_SAIN);
    expect(container.textContent).not.toContain(LIBELLE_LIBRAIRIE);
    expect(erreurRelancee).toBeNull();
  });
});

// ---------------------------------------------------------------------------
// 2. LA GARDE — la coquille doit survivre a l'explosion d'un ecran
// ---------------------------------------------------------------------------

describe('RootLayout — un ecran qui explose ne doit pas emporter la coquille', () => {
  it('affiche un message utile en francais ET conserve la coquille', async () => {
    const { container, erreurRelancee } = await monter({
      racine: 'reelle',
      coquille: 'reelle',
      ecran: EcranQuiExplose,
    });

    // a) Rien ne s'est echappe.
    expect(erreurRelancee).toBeNull();

    // b) L'utilisateur lit quelque chose d'utile, en francais.
    expect(container.textContent).toContain(LIBELLE_FRONTIERE);

    // c) ... et PLUS l'ecran anglais de la librairie. C'est l'assertion qui
    //    distingue « repare » de « la librairie a toujours fait quelque chose ».
    expect(container.textContent).not.toContain(LIBELLE_LIBRAIRIE);

    // d) La coquille est TOUJOURS la — `#main` n'existe que dans RootLayout
    //    (`src/app/RootLayout.tsx`). C'est ce qui distingue « un ecran est
    //    tombe » de « l'application est morte » : la navigation reste offerte.
    //    C'est CE point que le filet global de la librairie ne donnait pas.
    expect(container.querySelector('#main')).not.toBeNull();

    // e) L'ecran fautif, lui, n'est plus rendu.
    expect(container.textContent).not.toContain(MARQUEUR_ECRAN_SAIN);

    // f) La frontiere journalise (componentDidCatch) : sans cela l'incident
    //    serait invisible cote exploitation.
    expect(espionConsole).toHaveBeenCalled();
  });

  it('ne montre AUCUN message d erreur quand rien n explose', async () => {
    // Anti-vert-par-construction : une frontiere qui afficherait son message en
    // permanence passerait le cas precedent. Pas celui-ci.
    const { container } = await monter({
      racine: 'reelle',
      coquille: 'reelle',
      ecran: EcranSain,
    });

    expect(container.textContent).toContain(MARQUEUR_ECRAN_SAIN);
    expect(container.textContent).not.toContain(LIBELLE_FRONTIERE);
    expect(container.querySelector('[role="alert"]')).toBeNull();
  });

  it('se rearme apres navigation : un ecran tombe n empoisonne pas la coquille', async () => {
    // Defaut classique du montage naif : la frontiere garde `hasError: true` et
    // l'utilisateur reste bloque sur l'ecran d'erreur meme apres avoir clique
    // ailleurs dans la barre laterale. Le seul recours serait `F5`. C'est le
    // role de `ErrorBoundary.resetKey`.
    const { container, historique } = await monter({
      racine: 'reelle',
      coquille: 'reelle',
      ecran: EcranQuiExplose,
      ecranAilleurs: EcranSain,
    });

    expect(container.textContent).toContain(LIBELLE_FRONTIERE);

    // `historique.push` et non `router.navigate({ to })` : `AnyRouter` herite
    // du `Register` declare dans `src/main.tsx`, donc `to` n'accepte QUE les
    // chemins du vrai arbre de routes — `/ailleurs` n'en fait pas partie et
    // `tsc` refusait. Pousser dans l'historique est de toute facon plus proche
    // du geste reel : c'est ce que fait un clic sur un `Link`.
    historique.push('/ailleurs');

    await waitFor(() => {
      expect(container.textContent).toContain(MARQUEUR_ECRAN_SAIN);
    });
    expect(container.textContent).not.toContain(LIBELLE_FRONTIERE);
  });
});

// ---------------------------------------------------------------------------
// 3. LA RACINE DU ROUTEUR — les ecrans HORS coquille (le site jumeau)
// ---------------------------------------------------------------------------

describe('rootRoute — les ecrans d authentification, hors coquille', () => {
  it('couvre un ecran qui n est PAS enfant de la coquille', async () => {
    // `/login`, `/2fa`, `/magic-link`, `/password-reset` sont enfants de
    // `rootRoute` et non de `layoutRoute` (`src/app/routeTree.tsx`). Une
    // reparation qui n'aurait touche que `RootLayout` les aurait laisses sur
    // l'ecran anglais : c'est le SITE JUMEAU du defaut.
    //
    // On monte la racine REELLE, une coquille nue, et on fait exploser un ecran
    // place directement sous la racine.
    const rootRoute = createRootRoute({ component: COMPOSANT_RACINE_DE_PRODUCTION });
    const loginRoute = createRoute({
      getParentRoute: () => rootRoute,
      path: '/login',
      component: EcranQuiExplose,
    });
    const router: AnyRouter = createRouter({
      routeTree: rootRoute.addChildren([loginRoute as AnyRoute]),
      history: createMemoryHistory({ initialEntries: ['/login'] }),
      defaultPreload: 'intent',
    });
    await router.load();

    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    const container = document.createElement('div');
    document.body.appendChild(container);

    render(
      <QueryClientProvider client={queryClient}>
        <RouterProvider router={router} />
      </QueryClientProvider>,
      { container },
    );

    expect(container.textContent).toContain(LIBELLE_FRONTIERE);
    expect(container.textContent).not.toContain(LIBELLE_LIBRAIRIE);
    // Hors coquille il n'y a aucune navigation a offrir : le seul geste
    // possible est de recharger, et il doit etre PROPOSE (niveau `root`).
    expect(container.textContent).toContain(LIBELLE_RECHARGER);
  });
});

// ---------------------------------------------------------------------------
// 4. AppRoot — le filet de dernier recours, HORS du routeur
// ---------------------------------------------------------------------------

/**
 * Ici seulement, « page blanche » est le mot juste.
 *
 * Ce qui explose au-dessus de `RouterProvider` — `QueryClientProvider`,
 * `Toaster`, ou les entrailles du routeur — n'est vu par AUCUN des filets
 * precedents : celui de la librairie est pose A L'INTERIEUR de `RouterProvider`
 * (`Matches.js:36`), les notres sont plus profonds encore. L'exception remonte
 * alors jusqu'a la racine React, `createRoot().render()` releve, et le document
 * reste vide.
 *
 * COMMENT ON LE PROVOQUE, ET POURQUOI C'EST HONNETE : on pose
 * `disableGlobalCatchBoundary: true`, l'option prevue par la librairie
 * (`Matches.js:36`) pour retirer SON filet. On ne truque pas le resultat — on
 * retire le filet du dessous pour verifier que le NOTRE existe reellement
 * au-dessus. Sans cette option, la librairie attraperait avant et le test ne
 * dirait rien de `AppRoot`.
 */
describe('AppRoot — le filet de dernier recours', () => {
  function routeurSansFiletDeLibrairie(ecran: () => ReactElement): Promise<AnyRouter> {
    const rootRoute = createRootRoute({ component: Passthrough });
    const route = createRoute({
      getParentRoute: () => rootRoute,
      path: '/login',
      component: ecran,
    });
    const router: AnyRouter = createRouter({
      routeTree: rootRoute.addChildren([route as AnyRoute]),
      history: createMemoryHistory({ initialEntries: ['/login'] }),
      defaultPreload: 'intent',
      disableGlobalCatchBoundary: true,
    });
    return router.load().then(() => router);
  }

  it('attrape ce qu aucun filet du routeur ne voit', async () => {
    const router = await routeurSansFiletDeLibrairie(EcranQuiExplose);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    const container = document.createElement('div');
    document.body.appendChild(container);

    let erreurRelancee: unknown = null;
    try {
      render(<AppRoot router={router} queryClient={queryClient} />, { container });
    } catch (err) {
      erreurRelancee = err;
    }

    // Rien ne s'echappe jusqu'a la racine React : plus de page blanche.
    expect(erreurRelancee).toBeNull();
    expect(container.textContent).toContain(LIBELLE_FRONTIERE);
    // Au niveau racine il n'y a plus rien a offrir sinon recharger.
    expect(container.textContent).toContain(LIBELLE_RECHARGER);
  });

  it('TEMOIN — sans AppRoot, la meme explosion vide bien la page', async () => {
    // La preuve que le cas precedent doit son vert a `AppRoot` et pas au
    // hasard : meme routeur, meme ecran, mais monte SANS notre filet.
    const router = await routeurSansFiletDeLibrairie(EcranQuiExplose);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    const container = document.createElement('div');
    document.body.appendChild(container);

    let erreurRelancee: unknown = null;
    try {
      render(
        <QueryClientProvider client={queryClient}>
          <RouterProvider router={router} />
        </QueryClientProvider>,
        { container },
      );
    } catch (err) {
      erreurRelancee = err;
    }

    expect(container.textContent).toBe('');
    expect(erreurRelancee).not.toBeNull();
  });

  it('ne montre AUCUN message d erreur quand rien n explose', async () => {
    const router = await routeurSansFiletDeLibrairie(EcranSain);
    const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false, gcTime: 0 } } });
    const container = document.createElement('div');
    document.body.appendChild(container);

    render(<AppRoot router={router} queryClient={queryClient} />, { container });

    expect(container.textContent).toContain(MARQUEUR_ECRAN_SAIN);
    expect(container.textContent).not.toContain(LIBELLE_FRONTIERE);
  });
});
