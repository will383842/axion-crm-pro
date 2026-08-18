/**
 * `window.location` OBSERVABLE — et non « pas implémenté ».
 *
 * `src/lib/api.ts` redirige vers `/login` sur toute réponse 401 :
 *
 *     window.location.assign('/login')
 *
 * jsdom n'implémente pas la navigation : cet appel lève
 * `Not implemented: navigation (except hash changes)`. Le message part dans
 * stderr, le test échoue plus loin sur une assertion sans rapport, et on
 * cherche la panne au mauvais endroit. C'est exactement ce qui se produisait
 * dans la suite existante (voir le bruit `getaddrinfo ENOTFOUND api.localhost`
 * du run de référence).
 *
 * On ne peut PAS se contenter d'un `vi.spyOn(window.location, 'assign')` :
 * dans jsdom 25, `assign` est une propriété PROPRE de l'objet `Location`,
 * `writable: false` ET `configurable: false` — `defineProperty` lève. En
 * revanche `window.location` lui-même est `configurable: true`. On remplace
 * donc l'objet entier par un objet ordinaire qui :
 *
 *  - enregistre chaque `assign` / `replace` / `href = …` dans un journal,
 *  - MET À JOUR `pathname`, `search`, `href`… comme un vrai navigateur, parce
 *    que `api.ts` lit `window.location.pathname` pour ne pas boucler quand on
 *    est déjà sur `/login`. Un stub qui ne bougerait pas le `pathname` ferait
 *    passer un test que la vraie application aurait fait boucler.
 */

export interface LocationStub extends Location {
  /** Journal des navigations demandées, dans l'ordre. */
  readonly navigations: string[];
}

const DEFAULT_URL = 'http://localhost:3000/';

let stub: LocationStub | undefined;

function buildStub(initialUrl: string): LocationStub {
  const navigations: string[] = [];

  const target = {
    navigations,
    ancestorOrigins: [] as unknown as DOMStringList,
    assign(url: string | URL) {
      navigations.push(String(url));
      apply(String(url));
    },
    replace(url: string | URL) {
      navigations.push(String(url));
      apply(String(url));
    },
    reload() {
      navigations.push('reload');
    },
    toString() {
      return target.href;
    },
    href: initialUrl,
    origin: '',
    protocol: '',
    host: '',
    hostname: '',
    port: '',
    pathname: '',
    search: '',
    hash: '',
  };

  function apply(next: string): void {
    const url = new URL(next, target.href || DEFAULT_URL);
    target.href = url.href;
    target.origin = url.origin;
    target.protocol = url.protocol;
    target.host = url.host;
    target.hostname = url.hostname;
    target.port = url.port;
    target.pathname = url.pathname;
    target.search = url.search;
    target.hash = url.hash;
  }

  apply(initialUrl);
  // `target` satisfait structurellement `LocationStub` : aucune conversion
  // n'est nécessaire (et ESLint refuse une assertion qui ne change rien).
  return target;
}

/**
 * Installe le substitut. Appelé une fois par `tests/setup.ts` ; on peut le
 * rappeler dans un test pour repartir d'une autre URL (`setLocation`).
 */
export function installLocationStub(initialUrl: string = DEFAULT_URL): LocationStub {
  stub = buildStub(initialUrl);
  Object.defineProperty(window, 'location', {
    configurable: true,
    writable: true,
    value: stub,
  });
  return stub;
}

/** Repositionne l'URL courante SANS l'enregistrer comme une navigation. */
export function setLocation(url: string): void {
  installLocationStub(url);
}

/** Le journal des navigations demandées depuis le dernier `resetLocation()`. */
export function navigations(): string[] {
  return stub?.navigations ?? [];
}

/** La dernière navigation demandée, ou `undefined` s'il n'y en a pas eu. */
export function lastNavigation(): string | undefined {
  const list = navigations();
  return list[list.length - 1];
}

/**
 * Vrai si l'intercepteur 401 de `src/lib/api.ts` a fait son travail.
 * À préférer à une assertion sur `navigations()` : c'est le COMPORTEMENT
 * produit qu'on nomme, pas le détail de l'implémentation.
 */
export function wasRedirectedToLogin(): boolean {
  return navigations().some((url) => url.startsWith('/login'));
}

/** Remet le journal ET l'URL à zéro. Appelé automatiquement entre deux cas. */
export function resetLocation(url: string = DEFAULT_URL): void {
  installLocationStub(url);
}
