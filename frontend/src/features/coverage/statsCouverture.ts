export interface Cell {
  code: string;
  name: string;
  total: number;
  complete?: number;
  partial?: number;
  lat?: number;
  lon?: number;
}

export type Level = 'region' | 'department' | 'city';

/**
 * Nombre de zones de référence par niveau : 101 départements (métropole,
 * Corse 2A/2B et 5 d'outre-mer), 18 régions. L'ancien dénominateur (96 / 13)
 * ignorait l'outre-mer et pouvait dépasser 100 %.
 */
export const ZONES_PAR_NIVEAU: Record<Level, number | null> = { department: 101, region: 18, city: null };

/**
 * Indicateurs de la carte, calculés sur TOUTES les cellules rendues par le
 * serveur. `withScore` = fiches au score de qualité ≥ 50 (complètes +
 * partielles) : l'ancien « N complètes » (score ≥ 90) affichait 0 partout,
 * aucun barème actuel n'y atteignant — un 0 trompeur.
 */
export function statsCouverture(cells: Cell[], level: Level) {
  const totalAll = cells.reduce((s, c) => s + Number(c.total ?? 0), 0);
  const withScore = cells.reduce((s, c) => s + Number(c.complete ?? 0) + Number(c.partial ?? 0), 0);
  const covered = cells.filter((c) => Number(c.total ?? 0) > 0).length;
  const denom = ZONES_PAR_NIVEAU[level] ?? Math.max(cells.length, 1);
  const pct = denom ? Math.min(100, Math.round((covered / denom) * 100)) : 0;
  const top = [...cells].sort((a, b) => Number(b.total ?? 0) - Number(a.total ?? 0)).slice(0, 8);
  return { totalAll, withScore, covered, denom, pct, top };
}

