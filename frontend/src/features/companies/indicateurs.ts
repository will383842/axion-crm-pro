import { libelleReferentiel } from '@/lib/prospection-referentiels';
import { SECTEURS, TAILLES } from '@/lib/referentiels.generated';

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
}

/**
 * Les trois vignettes de l'écran Entreprises. Sans chiffre du serveur (en
 * cours de calcul, panne) : « — », jamais une valeur tirée de la page
 * affichée.
 */
export function indicateursEntreprises(s: StatsBase | undefined): IndicateursEntreprises {
  const enrichiesPct = typeof s?.enrichies_pct === 'number' ? s.enrichies_pct : null;
  const taille = s?.top_taille ?? null;
  const secteur = s?.top_secteur ?? null;

  return {
    enrichies: enrichiesPct === null ? '—' : `≈ ${enrichiesPct} %`,
    enrichiesSous: enrichiesPct === null ? 'calcul en cours' : 'des fiches (estimation sur 1 % de la base)',
    enrichiesPct,
    taille: taille ? (libelleReferentiel(TAILLES, taille.code) ?? taille.code) : '—',
    tailleSous: taille ? `${taille.pct} % des fiches` : 'calcul en cours',
    taillePct: taille ? taille.pct : null,
    secteur: secteur ? (libelleReferentiel(SECTEURS, secteur.code) ?? secteur.code) : '—',
    secteurSous: secteur ? `${secteur.n.toLocaleString('fr-FR')} fiches (${secteur.pct} %)` : 'calcul en cours',
  };
}
