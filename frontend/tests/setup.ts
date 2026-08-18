/**
 * Socle de la suite unitaire — chargé par `vitest.config.ts` (`setupFiles`).
 *
 * Avant : une ligne (`import '@testing-library/jest-dom/vitest'`). Il manquait
 * tout ce qui fait qu'un écran RÉEL, sous jsdom, échoue sur une VRAIE
 * assertion plutôt que sur une lacune de l'environnement :
 *
 *  - `cleanup()` entre les cas (sans lui, deux rendus du même écran coexistent
 *    dans le DOM et `getByRole` lève « found multiple elements »),
 *  - `matchMedia`, `ResizeObserver`, `IntersectionObserver`, `scrollTo` : jsdom
 *    ne les implémente pas, et la moindre carte responsive les appelle,
 *  - `window.location.assign` (cf. `tests/helpers/location.ts`),
 *  - MSW, avec `onUnhandledRequest: 'error'` : une requête non simulée est une
 *    ERREUR, pas un `getaddrinfo ENOTFOUND` noyé dans stderr. Le run de
 *    référence en produisait à chaque exécution — le test restait vert.
 */
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterAll, afterEach, beforeAll, beforeEach, vi } from 'vitest';
import { server } from './msw/server';
import { installLocationStub, resetLocation } from './helpers/location';

// ---------------------------------------------------------------------------
// Environnement navigateur manquant sous jsdom
// ---------------------------------------------------------------------------

if (typeof window.matchMedia !== 'function') {
  Object.defineProperty(window, 'matchMedia', {
    writable: true,
    configurable: true,
    value: (query: string): MediaQueryList =>
      ({
        matches: false,
        media: query,
        onchange: null,
        addListener: () => {},
        removeListener: () => {},
        addEventListener: () => {},
        removeEventListener: () => {},
        dispatchEvent: () => false,
      }),
  });
}

class NoopObserver {
  observe(): void {}
  unobserve(): void {}
  disconnect(): void {}
  takeRecords(): [] {
    return [];
  }
  readonly root = null;
  readonly rootMargin = '';
  readonly thresholds: readonly number[] = [];
}

if (typeof globalThis.ResizeObserver === 'undefined') {
  globalThis.ResizeObserver = NoopObserver;
}
if (typeof globalThis.IntersectionObserver === 'undefined') {
  globalThis.IntersectionObserver = NoopObserver;
}

// jsdom déclare `scrollTo` mais lève « Not implemented ». On l'écrase.
Object.defineProperty(window, 'scrollTo', { writable: true, configurable: true, value: () => {} });
Object.defineProperty(Element.prototype, 'scrollIntoView', {
  writable: true,
  configurable: true,
  value: () => {},
});

// `RootLayout` ouvre une connexion laravel-echo/pusher dès qu'un workspace est
// connu. Le levier existe DÉJÀ dans le code produit (`RootLayout.tsx:41`) : on
// s'en sert au lieu de simuler `@/lib/echo`, pour que le chemin testé soit
// celui qui tourne réellement quand l'exploitation coupe le temps réel.
vi.stubEnv('VITE_ECHO_DISABLED', 'true');

installLocationStub();

// ---------------------------------------------------------------------------
// MSW
// ---------------------------------------------------------------------------

beforeAll(() => {
  server.listen({ onUnhandledRequest: 'error' });
});

beforeEach(() => {
  // Le journal des navigations est propre à chaque cas ; sinon un `assign`
  // laissé par le cas précédent ferait passer une assertion de redirection.
  resetLocation();
});

afterEach(() => {
  cleanup();
  server.resetHandlers();
  window.localStorage.clear();
  window.sessionStorage.clear();
});

afterAll(() => {
  server.close();
});

/**
 * ⚠️ DRAPEAU DE MODULE `csrfFetched` (`src/lib/api.ts:13`)
 *
 * `ensureCsrf()` ne demande le cookie QU'UNE FOIS par instance de module. Or
 * vitest isole le graphe de modules PAR FICHIER de test, pas par cas : à
 * l'intérieur d'un même fichier, seul le PREMIER POST déclenche
 * `GET /sanctum/csrf-cookie`. Les suivants passent sans.
 *
 * On ne le remet pas à zéro ici, et c'est délibéré : `vi.resetModules()` dans
 * un `afterEach` ne rembobinerait QUE les imports dynamiques ultérieurs. Les
 * écrans sont importés statiquement en tête de fichier, ils garderaient leur
 * `api` d'origine — le reset serait décoratif, donc mensonger.
 *
 * Conséquence pratique, à connaître avant d'écrire un test :
 *   • assurer « le POST est bien parti » → toujours possible, n'importe où ;
 *   • assurer « le cookie CSRF a été demandé AVANT le POST » → ne vaut que
 *     dans un fichier dédié où c'est le premier POST. Utiliser
 *     `resetCsrfFlag()` (`tests/helpers/renderScreen.tsx`) et ré-importer
 *     l'écran dynamiquement si vraiment nécessaire.
 * Le contrat est vérifié dans `tests/lib/msw-contract.test.ts`.
 */
