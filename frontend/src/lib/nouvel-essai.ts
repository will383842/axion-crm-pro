/**
 * La règle des NOUVEAUX ESSAIS automatiques des requêtes React Query
 * (`main.tsx`, `defaultOptions.queries.retry`), sortie ici pour être testée :
 * le client de test des écrans tourne en `retry: false`, il ne peut donc rien
 * prouver sur cette règle.
 *
 * On ne réessaie PAS quand le serveur a répondu et que sa réponse ne changera
 * pas en réessayant :
 *  - 401 : session à rouvrir (l'intercepteur redirige déjà) ;
 *  - 403 : refus de droits ;
 *  - 409 : conflit d'état, par ex. `no_workspace` / `workspace_not_selected`
 *    du tableau de bord (audit UX P0-1) — réessayer ne ferait que prolonger
 *    le squelette d'environ 3 s avant d'afficher la vraie raison.
 *
 * Tout le reste (5xx, réseau coupé, délai dépassé) est réessayé deux fois.
 */
export const NOUVEAUX_ESSAIS_MAX = 2;

const STATUTS_DEFINITIFS: ReadonlySet<number> = new Set([401, 403, 409]);

export function doitReessayer(echecs: number, err: unknown): boolean {
  const status = (err as { response?: { status?: number } } | null)?.response?.status;
  if (typeof status === 'number' && STATUTS_DEFINITIFS.has(status)) return false;
  return echecs < NOUVEAUX_ESSAIS_MAX;
}
