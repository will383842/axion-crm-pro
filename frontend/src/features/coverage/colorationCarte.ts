/**
 * Coloration de la carte des départements à partir des cellules de l'API.
 *
 * 🔴 Constat en production (2026-10-02) : tous les départements restaient
 * gris « 0 » alors que la liste « Top zones » montrait Paris à 615 507.
 * L'ancienne coloration passait par `querySourceFeatures`, qui ne rend que
 * les entités DÉJÀ chargées et visibles ; quand les cellules arrivaient avant
 * la fin du chargement du GeoJSON, rien n'était coloré, et rien ne relançait
 * la coloration ensuite.
 *
 * Désormais :
 *  - la source porte `promoteId: 'code'` : l'identifiant d'une entité EST son
 *    code de département (« 01 », « 2A », « 971 ») ;
 *  - on écrit directement `setFeatureState({ id: code })` pour chaque cellule,
 *    sans balayer la source ;
 *  - on réapplique quand les cellules changent ET quand la source finit de
 *    charger (`sourcedata`).
 *
 * Ce module n'importe PAS maplibre-gl : il se teste sans WebGL et ne crée
 * aucune dépendance statique vers le gros morceau de la carte.
 */

export const SOURCE_DEPARTEMENTS = 'departements';

/** Ce dont la coloration a besoin d'une carte MapLibre — et rien de plus. */
export interface CarteColorable {
  getSource(id: string): unknown;
  isSourceLoaded(id: string): boolean;
  setFeatureState(cible: { source: string; id: string | number }, etat: Record<string, unknown>): void;
}

export interface CelluleColoree {
  code: string;
  total: number;
}

/**
 * Le code tel que le GeoJSON le porte : deux chiffres pour la métropole
 * (« 01 », pas « 1 »), « 2A »/« 2B » pour la Corse, trois chiffres outre-mer.
 */
export function codeDepartement(code: string): string {
  const net = code.trim().toUpperCase();
  return /^\d$/.test(net) ? `0${net}` : net;
}

/**
 * Applique les totaux des cellules aux départements de la carte.
 *
 * Rend l'ensemble des codes colorés, ou `null` si la source n'est pas encore
 * chargée (rien n'a été écrit : l'appelant réessaiera sur `sourcedata`).
 * Les départements colorés la fois précédente et absents cette fois-ci
 * repassent à 0, pour ne pas garder une couleur périmée.
 */
export function appliquerTotaux(
  carte: CarteColorable,
  cellules: readonly CelluleColoree[],
  precedents: ReadonlySet<string> = new Set(),
): Set<string> | null {
  if (!carte.getSource(SOURCE_DEPARTEMENTS) || !carte.isSourceLoaded(SOURCE_DEPARTEMENTS)) {
    return null;
  }
  const colores = new Set<string>();
  for (const cellule of cellules) {
    const id = codeDepartement(cellule.code);
    const total = Number(cellule.total);
    carte.setFeatureState(
      { source: SOURCE_DEPARTEMENTS, id },
      { total: Number.isFinite(total) ? total : 0 },
    );
    colores.add(id);
  }
  for (const ancien of precedents) {
    if (!colores.has(ancien)) {
      carte.setFeatureState({ source: SOURCE_DEPARTEMENTS, id: ancien }, { total: 0 });
    }
  }
  return colores;
}
