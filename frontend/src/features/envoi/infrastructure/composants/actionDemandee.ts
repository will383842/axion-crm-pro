/**
 * DM2-INF — « ouvrir une fenêtre d'ajout depuis Ctrl K ».
 *
 * La palette ne peut pas passer de paramètre d'adresse : elle appelle
 * `demanderAction`, qui note l'action, amène la page et la réveille. La page
 * qui possède la fenêtre la consomme (`useActionDemandee`) à l'ouverture ou
 * dès qu'elle est déjà affichée.
 */
import { useEffect } from 'react';

const CLE = 'axion-envoi:action-demandee';
const EVENEMENT = 'axion-envoi:action';

export type ActionDemandee = 'ajouter-ip' | 'ajouter-domaine';

export function demanderAction(action: ActionDemandee, chemin: string): void {
  try {
    globalThis.sessionStorage?.setItem(CLE, action);
  } catch {
    // stockage indisponible : la page s'ouvre, sans la fenêtre.
  }
  if (globalThis.location?.pathname !== chemin) {
    globalThis.history.pushState(null, '', chemin);
    globalThis.dispatchEvent(new PopStateEvent('popstate'));
  }
  globalThis.dispatchEvent(new Event(EVENEMENT));
}

function consommer(action: ActionDemandee): boolean {
  try {
    if (globalThis.sessionStorage?.getItem(CLE) !== action) return false;
    globalThis.sessionStorage.removeItem(CLE);
    return true;
  } catch {
    return false;
  }
}

/** Appelle `ouvrir` quand l'action est demandée (à l'ouverture de la page ou après). */
export function useActionDemandee(action: ActionDemandee, ouvrir: () => void): void {
  useEffect(() => {
    if (consommer(action)) ouvrir();
    const ecoute = () => {
      if (consommer(action)) ouvrir();
    };
    globalThis.addEventListener(EVENEMENT, ecoute);
    return () => globalThis.removeEventListener(EVENEMENT, ecoute);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [action]);
}
