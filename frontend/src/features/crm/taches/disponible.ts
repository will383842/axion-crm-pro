/**
 * Mes tâches et Affaires (DM1-ACC, puis lots G2-B et G2-D).
 *
 * « Mes tâches » est servi par le serveur (`/crm/taches`, lot G2-A) et
 * « Affaires » aussi (`/crm/affaires`, lot G2-D) : les deux écrans sont ouverts
 * partout, démonstration ou non.
 */
export function tachesDisponibles(): boolean {
  return true;
}

export function affairesDisponibles(): boolean {
  return true;
}

/**
 * @deprecated Ancien nom, gardé le temps que les écrans qui ne font pas partie du
 * lot G2-B (`AffairesPage`, `DashboardPage`) passent à `affairesDisponibles()` /
 * `tachesDisponibles()`. Il suit « Affaires » (désormais toujours vrai).
 */
export function tachesEtAffairesDisponibles(): boolean {
  return affairesDisponibles();
}
