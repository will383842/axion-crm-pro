import { libelleReferentiel } from '@/lib/prospection-referentiels';
import { SECTEURS, TAILLES } from '@/lib/referentiels.generated';
import { misAJour } from '@/lib/fraicheur';

/**
 * Réponse de `GET /companies/stats` (lot 3, 2026-10-02) : des chiffres de
 * TOUTE la base de l'espace, mis en cache côté serveur.
 */
export interface StatsBase {
  total: number | null;
  /** ESTIMATION sur un échantillon de 1 % des fiches. */
  enrichies_pct: number | null;
  top_taille: { code: string; n: number; pct: number } | null;
  top_secteur: { code: string; n: number; pct: number } | null;
  computed_at?: string | null;
}

export interface IndicateursEntreprises {
  enrichies: string;
  enrichiesSous: string;
  enrichiesPct: number | null;
  taille: string;
  tailleSous: string;
  taillePct: number | null;
  secteur: string;
  secteurSous: string;
  /** « mis à jour il y a N min » (chiffres en cache côté serveur). */
  fraicheur: string | null;
}

/**
 * Les trois vignettes de l'écran Entreprises. Sans chiffre du serveur (en
 * cours de calcul, panne) : « — », jamais une valeur tirée de la page
 * affichée.
 */
export function indicateursEntreprises(s: StatsBase | undefined, indisponible = false): IndicateursEntreprises {
  // « calcul en cours » n'est vrai que tant que le serveur travaille : sur une
  // panne (ou un calcul qui n'aboutit pas), l'écran dit « indisponible ».
  const attente = indisponible ? 'indisponible pour le moment' : 'calcul en cours';
  const enrichiesPct = typeof s?.enrichies_pct === 'number' ? s.enrichies_pct : null;
  const taille = s?.top_taille ?? null;
  const secteur = s?.top_secteur ?? null;

  return {
    enrichies: enrichiesPct === null ? '—' : `≈ ${enrichiesPct} %`,
    enrichiesSous: enrichiesPct === null ? attente : 'des fiches (estimation sur 1 % de la base)',
    enrichiesPct,
    taille: taille ? (libelleReferentiel(TAILLES, taille.code) ?? taille.code) : '—',
    // `pct` est rapporté à TOUTES les fiches (serveur), valeur absente comprise.
    tailleSous: taille ? `${taille.pct} % de toutes les fiches` : attente,
    taillePct: taille ? taille.pct : null,
    secteur: secteur ? (libelleReferentiel(SECTEURS, secteur.code) ?? secteur.code) : '—',
    secteurSous: secteur ? `${secteur.n.toLocaleString('fr-FR')} fiches (${secteur.pct} % de toutes les fiches)` : attente,
    fraicheur: misAJour(s?.computed_at ?? null),
  };
}

/** Au-delà de ce délai sans chiffre, le calcul serveur est tenu pour échoué. */
export const ATTENTE_MAX_STATS_MS = 45_000;

/**
 * Les cartes sont « indisponibles » (et non « en cours de calcul » pour
 * toujours) quand la requête a échoué sans aucune réponse antérieure, ou que le
 * serveur répond encore sans chiffre (`computed_at` nul) passé le délai.
 */
export function statsIndisponibles(entree: { stats: StatsBase | undefined; enErreur: boolean; attenteMs: number }): boolean {
  if (entree.stats === undefined) return entree.enErreur;
  return entree.stats.computed_at == null && entree.attenteMs >= ATTENTE_MAX_STATS_MS;
}
