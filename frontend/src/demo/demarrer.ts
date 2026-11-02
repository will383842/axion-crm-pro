/**
 * Démarrage du mode démo — importé DYNAMIQUEMENT par `main.tsx`, et seulement
 * si `import.meta.env.VITE_DEMO === '1'` : le build normal n'en contient aucun
 * octet (garde : `tests/lib/demo-absent-du-build-normal.test.ts`).
 *
 * 1. Lit les réglages de l'adresse (`?demo-offre=`, `?demo-etat=`,
 *    `?demo-scenarios=non`, `?demo-session=connecte`), les range dans la
 *    session, puis les retire de l'adresse.
 * 2. Démarre le faux serveur MSW dans le navigateur (`setupWorker`), avec
 *    tous les handlers (`handlers/index.ts`) et, en dernier recours, une
 *    réponse « pas encore servi » : aucune requête d'API ne sort. Tout autre
 *    hôte que la page (tuiles de carte, services tiers…) est refusé, avec un
 *    avertissement dans la console (`reseau.ts`).
 * 3. Lance les scénarios rejoués.
 */
import type { ComponentType } from 'react';
import { http, HttpResponse } from 'msw';
import { setupWorker } from 'msw/browser';
import { handlers } from './handlers';
import { BandeauDemo } from './BandeauDemo';
import { choisirOffre, estOffre } from './offres';
import { definirEtatForce } from './outils';
import { definirScenariosActifs, demarrerScenarios } from './scenarios';
import { ecrireSession } from './session';
import { gardeReseau } from './reseau';

const PARAMETRES = ['demo-offre', 'demo-etat', 'demo-scenarios', 'demo-session'] as const;

function lireReglagesDeLAdresse(): void {
  const url = new URL(window.location.href);
  const p = url.searchParams;
  const offre = p.get('demo-offre');
  if (estOffre(offre)) choisirOffre(offre);
  const etat = p.get('demo-etat');
  if (etat === 'vide' || etat === 'erreur' || etat === 'chargement') definirEtatForce(etat);
  else if (etat === 'normal') definirEtatForce(null);
  const scenarios = p.get('demo-scenarios');
  if (scenarios !== null) definirScenariosActifs(scenarios !== 'non');
  if (p.get('demo-session') === 'connecte') ecrireSession('connecte');
  if (PARAMETRES.some((k) => p.has(k))) {
    PARAMETRES.forEach((k) => p.delete(k));
    window.history.replaceState(window.history.state, '', `${url.pathname}${url.search}${url.hash}`);
  }
}

/** Dernier recours : une route d'API sans handler répond 501, elle ne part pas sur le réseau. */
const pasEncoreServi = http.all('*/api/v1/*', () =>
  HttpResponse.json(
    { error: 'demo_non_servi', message: 'Cet écran n’est pas encore servi par la démonstration.' },
    { status: 501 },
  ),
);

export interface DemoDemarree {
  Bandeau: ComponentType;
  /** Appelé quand un scénario a changé les données (l'application les relit). */
  surChangement: (rappel: () => void) => void;
}

export async function demarrerDemo(): Promise<DemoDemarree> {
  lireReglagesDeLAdresse();
  const worker = setupWorker(...handlers, pasEncoreServi, gardeReseau(window.location.origin));
  await worker.start({
    serviceWorker: { url: '/mockServiceWorker.js' },
    // Ne devrait jamais arriver (la garde réseau prend tout) : on avertit quand même.
    onUnhandledRequest: (request) => {
      if (new URL(request.url).origin !== window.location.origin) console.warn(`[démo] Requête non gérée, filet de secours (la garde réseau aurait dû la prendre) : ${request.method} ${request.url}`);
    },
    quiet: true,
  });
  let rappels: Array<() => void> = [];
  demarrerScenarios(() => rappels.forEach((r) => r()));
  return {
    Bandeau: BandeauDemo,
    surChangement: (rappel) => {
      rappels = [...rappels, rappel];
    },
  };
}
