/**
 * Garde réseau de la démo : AUCUNE requête ne doit quitter le navigateur.
 *
 * Le dernier recours 501 (`demarrer.ts`) ne couvre que les routes `/api/v1/`. Tout le
 * reste passe ici, en tout dernier :
 *  - même origine que la page (scripts, styles, images du site, le
 *    `mockServiceWorker.js` lui-même) : laissé passer, c'est le site ;
 *  - tout autre hôte (tuiles de carte, polices, `demo.invalid` hors API,
 *    services tiers…) : REFUSÉ par une erreur réseau, avec un avertissement
 *    dans la console qui donne l'adresse, pour qu'un lot d'écran voie tout de
 *    suite qu'il lui manque un handler.
 */
import { http, HttpResponse, passthrough } from 'msw';

export type DecisionReseau = 'laisser' | 'refuser';

/** Décide du sort d'une requête qu'aucun handler n'a servie. */
export function deciderRequeteNonGeree(url: string, originePage: string): DecisionReseau {
  let cible: URL;
  try {
    cible = new URL(url, originePage);
  } catch {
    return 'refuser';
  }
  if (cible.protocol === 'data:' || cible.protocol === 'blob:') return 'laisser';
  return cible.origin === new URL(originePage).origin ? 'laisser' : 'refuser';
}

export const MESSAGE_REFUS = '[démo] Requête refusée (aucune requête ne sort de la démonstration) :';

/** Handler à placer en TOUT DERNIER dans `setupWorker`. */
export function gardeReseau(originePage: string, avertir: (message: string) => void = console.warn) {
  return http.all('*', ({ request }) => {
    if (deciderRequeteNonGeree(request.url, originePage) === 'laisser') return passthrough();
    avertir(`${MESSAGE_REFUS} ${request.method} ${request.url}`);
    return HttpResponse.error();
  });
}
