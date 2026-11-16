/**
 * Mes tâches et Affaires (DM1-ACC, puis lot G2-B).
 *
 * « Mes tâches » est servi par le serveur (`/crm/taches`, lot G2-A) : l'écran
 * est ouvert partout. « Affaires » n'a pas encore ses routes (lot G2-D) : cet
 * écran ne vit qu'en démonstration (`VITE_DEMO`, figé au build). Hors démo,
 * /affaires garde sa page « en attente » neutre, Ctrl K ne le propose pas et
 * aucun appel ne part vers une route absente.
 */
export function tachesDisponibles(): boolean {
  return true;
}

export function affairesDisponibles(): boolean {
  return import.meta.env.VITE_DEMO === '1';
}

/**
 * @deprecated Ancien nom, gardé le temps que les écrans qui ne font pas partie du
 * lot G2-B (`AffairesPage`, `DashboardPage`) passent à `affairesDisponibles()` /
 * `tachesDisponibles()`. Il suit « Affaires » (le plus prudent : démo seulement).
 */
export function tachesEtAffairesDisponibles(): boolean {
  return affairesDisponibles();
}
