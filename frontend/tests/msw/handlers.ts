/**
 * MSW — les fabriques de réponses partagées.
 *
 * ═══ POURQUOI MSW ET NON `vi.mock('@/lib/api')` ═══
 *
 * Le dépôt pratiquait le mock de module (`tests/components/ContactsHubPage.test.tsx`).
 * C'est plus rapide, mais ça REMPLACE `src/lib/api.ts` — donc ça retire du
 * chemin de test tout ce que ce fichier contient :
 *
 *  1. `ensureCsrf()` : aucun test de mock de module ne peut voir qu'un POST
 *     déclenche (ou ne déclenche pas) `GET /sanctum/csrf-cookie`. Or c'est le
 *     seul mécanisme d'authentification d'écriture de l'application.
 *  2. L'intercepteur 401 → `window.location.assign('/login')`. Avec un mock de
 *     module, un écran peut « échouer » sans jamais rediriger et le test reste
 *     vert. La déconnexion est un comportement produit, pas un détail.
 *  3. Les paramètres RÉELLEMENT envoyés : `api.get('/x', { params })` sérialise
 *     via axios. Un mock de module reçoit l'objet `params`, jamais l'URL. Un
 *     test qui assure « on envoie `temperature=froids` » ne prouve rien sur la
 *     requête HTTP tant qu'axios n'a pas construit la query string.
 *
 * MSW intercepte AU NIVEAU RÉSEAU (XHR sous jsdom) : les trois chemins
 * ci-dessus restent dans le test. Vérifié : voir `tests/lib/msw-contract.test.ts`.
 *
 * Ce que MSW coûte : ~0,3 s de démarrage par fichier de test, et l'obligation
 * d'écrire des URLs absolues. Ce que le mock de module aurait coûté : les trois
 * points ci-dessus resteraient NON COUVERTS, définitivement.
 *
 * ═══ COMMENT AJOUTER UN ÉCRAN ═══
 *
 * On n'édite PAS ce fichier pour un écran : on passe ses handlers à
 * `renderScreen({ handlers: [...] })`, qui les empile via `server.use()` pour
 * la durée du cas. Ce fichier ne porte que ce qui est commun à TOUS les écrans
 * (CSRF, `/auth/me`, `/config/features`).
 */
import { http, HttpResponse, type HttpHandler } from 'msw';

/**
 * ⚠️ Doit rester aligné sur `src/lib/api.ts:3` :
 *     `import.meta.env['VITE_API_BASE_URL'] ?? 'https://api.localhost'`
 * Aucune variable d'environnement n'est définie sous vitest → la valeur par
 * défaut s'applique. Si quelqu'un ajoute un `.env.test`, il devra la propager
 * ici, sinon TOUTES les requêtes deviendront « non gérées » (donc rouges).
 */
export const API_ORIGIN = 'https://api.localhost';
export const API_BASE = `${API_ORIGIN}/api/v1`;

/** URL absolue d'un chemin d'API (`/auth/me` → `https://api.localhost/api/v1/auth/me`). */
export function apiUrl(path: string): string {
  return `${API_BASE}${path.startsWith('/') ? path : `/${path}`}`;
}

// ---------------------------------------------------------------------------
// Raccourcis — un handler = une ligne
// ---------------------------------------------------------------------------

/** `GET <path>` répond `body` en 200. */
export function getJson(path: string, body: unknown): HttpHandler {
  return http.get(apiUrl(path), () => HttpResponse.json(body as never));
}

/** `POST <path>` répond `body` en 200 (ou `status`). */
export function postJson(path: string, body: unknown, status = 200): HttpHandler {
  return http.post(apiUrl(path), () => HttpResponse.json(body as never, { status }));
}

/** `GET <path>` répond un code d'erreur nu. `401` déclenche la redirection login. */
export function getStatus(path: string, status: number): HttpHandler {
  return http.get(apiUrl(path), () => new HttpResponse(null, { status }));
}

/** `POST <path>` répond un code d'erreur, avec un corps d'erreur Laravel optionnel. */
export function postStatus(path: string, status: number, body?: unknown): HttpHandler {
  return http.post(apiUrl(path), () =>
    body === undefined
      ? new HttpResponse(null, { status })
      : HttpResponse.json(body as never, { status }),
  );
}

/**
 * `GET <path>` qui reste EN VOL jusqu'à ce qu'on appelle `release()`.
 *
 * Indispensable pour tester un état transitoire (squelette, `isPlaceholderData`)
 * : c'est le seul moment où certains défauts existent — cf. le mensonge de
 * l'état vide corrigé sur `ContactsHubPage`. Un test d'état stable ne peut pas
 * les voir.
 */
export function getPending(path: string, body: unknown): { handler: HttpHandler; release: () => void } {
  let release!: () => void;
  const gate = new Promise<void>((resolve) => {
    release = resolve;
  });
  return {
    handler: http.get(apiUrl(path), async () => {
      await gate;
      return HttpResponse.json(body as never);
    }),
    release,
  };
}

/**
 * Capture les URLs complètes reçues sur un chemin (query string comprise).
 * Le tableau retourné se remplit au fil des requêtes.
 */
export function recordGet(path: string, body: unknown): { handler: HttpHandler; urls: string[] } {
  const urls: string[] = [];
  return {
    handler: http.get(apiUrl(path), ({ request }) => {
      urls.push(request.url);
      return HttpResponse.json(body as never);
    }),
    urls,
  };
}

/**
 * `GET <path>` dont le corps est CALCULÉ à chaque appel.
 *
 * C'est le seul moyen de prouver qu'un écran s'est RE-RENDU après un
 * rafraîchissement, et pas seulement qu'il a re-requêté : la deuxième réponse
 * diffère de la première, et l'assertion porte sur ce qui est affiché.
 */
export function dynamicGet(
  path: string,
  body: (appel: number, url: URL) => unknown,
): { handler: HttpHandler; urls: string[] } {
  const urls: string[] = [];
  return {
    handler: http.get(apiUrl(path), ({ request }) => {
      urls.push(request.url);
      return HttpResponse.json(body(urls.length, new URL(request.url)) as never);
    }),
    urls,
  };
}

/**
 * Capture les corps reçus sur un POST.
 *
 * ⚠️ `request.json()` LÈVE sur un corps vide, et plusieurs mutations de
 * l'application postent sans corps (`api.post('/companies/42/enrich')`). MSW
 * transforme alors l'exception en « unhandled exception during handler lookup »
 * : le POST n'est jamais enregistré et le test échoue en accusant l'écran.
 * On lit donc le texte, et un corps vide vaut `null`.
 */
export function recordPost<T = unknown>(
  path: string,
  body: unknown,
  status = 200,
): { handler: HttpHandler; bodies: (T | null)[] } {
  const bodies: (T | null)[] = [];
  return {
    handler: http.post(apiUrl(path), async ({ request }) => {
      const brut = await request.text();
      bodies.push(brut.length === 0 ? null : (JSON.parse(brut) as T));
      return HttpResponse.json(body as never, { status });
    }),
    bodies,
  };
}

// ---------------------------------------------------------------------------
// Fixtures communes
// ---------------------------------------------------------------------------

/** Console v2 OUVERTE, univers business seul (le cas nominal d'un opérateur). */
export const CONSOLE_FEATURES_OPEN = {
  console_v2: true,
  universes: { business: true, vivier: false },
} as const;

/** Console v2 FERMÉE — le drapeau `CRM_CONSOLE_V2_ENABLED` est à `false`. */
export const CONSOLE_FEATURES_CLOSED = {
  console_v2: false,
  universes: { business: false, vivier: false },
} as const;

export const ME_FIXTURE = {
  user: {
    id: 'u-1',
    name: 'Will Test',
    email: 'contact@axion-ia.com',
    current_workspace_id: 'ws-1',
  },
} as const;

// ---------------------------------------------------------------------------
// Handlers par défaut — actifs pour TOUS les fichiers de test
// ---------------------------------------------------------------------------

/**
 * Le socle. Sans lui, `server.listen({ onUnhandledRequest: 'error' })` ferait
 * rougir tout écran monté sous `RootLayout` (qui appelle `/auth/me`) ou sous
 * `ConsoleGate` (qui appelle `/config/features`).
 *
 * `/config/features` répond FERMÉ par défaut : c'est l'état d'un serveur qui
 * n'a pas le drapeau. Un écran console doit donc OUVRIR explicitement la
 * console — `renderScreen({ consoleFeatures: 'open' })`. Le défaut ne doit
 * jamais être la configuration la plus permissive.
 */
export const defaultHandlers: HttpHandler[] = [
  http.get(`${API_ORIGIN}/sanctum/csrf-cookie`, () => new HttpResponse(null, { status: 204 })),
  getJson('/auth/me', ME_FIXTURE),
  getJson('/config/features', CONSOLE_FEATURES_CLOSED),
  // D24-002 — depuis que la cloche de l'en-tete est branchee, `RootLayout`
  // interroge `/notifications` au montage, exactement comme `/auth/me`. Sans
  // ce socle, TOUT test monte avec `withLayout: true` rougirait sur
  // `onUnhandledRequest: 'error'` pour une raison etrangere a ce qu'il teste.
  // Corps NEUTRE : aucune notification, aucune non lue.
  getJson('/notifications', { data: [], unread_count: 0 }),
];

// Ré-export DIRECT (`export … from`) et non `import` puis `export { … }` :
// sous la transformation SWC, la seconde forme rendait `http` indéfini dans les
// fichiers de test. Le symptôme (`Cannot read properties of undefined`) n'avait
// aucun rapport visible avec la cause.
export { http, HttpResponse } from 'msw';
export type { HttpHandler };
