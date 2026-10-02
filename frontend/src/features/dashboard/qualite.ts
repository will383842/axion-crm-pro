/**
 * L'ÉTAT DU SCORE DE QUALITÉ AFFICHÉ SUR L'ACCUEIL (lot 3, 2026-10-02).
 *
 * Avant : l'écran recalculait une « moyenne » pondérée (100 / 60 / 25) à
 * partir d'une répartition que le serveur rendait toujours à zéro (il lisait
 * une colonne `quality_tier` inexistante) — d'où « Qualité moyenne 0/100 »
 * sur 4,3 M de fiches.
 *
 * Désormais la moyenne vient du serveur (`quality_avg`, moyenne réelle de
 * `quality_score`), et le serveur estime la part des scores PÉRIMÉS
 * (`quality_a_recalculer_pct`, sur échantillon). Trois états :
 *   - `indisponible` : pas de chiffre (panne, ancien serveur) → « — » ;
 *   - `en_attente`   : trop de scores périmés pour qu'une moyenne veuille
 *                      dire quelque chose → « calcul en attente » ;
 *   - `ok`           : la moyenne est affichée.
 * Jamais un 0 trompeur.
 */
export interface DonneesQualite {
  quality_avg?: number | null;
  quality_a_recalculer_pct?: number | null;
}

export type EtatQualite =
  | { etat: 'indisponible' }
  | { etat: 'en_attente'; pctARecalculer: number }
  | { etat: 'ok'; moyenne: number };

/** Au-delà de cette part de scores périmés, la moyenne n'est pas montrée. */
export const SEUIL_PERIMES_PCT = 10;

export function etatQualite(d: DonneesQualite): EtatQualite {
  const pct = typeof d.quality_a_recalculer_pct === 'number' ? d.quality_a_recalculer_pct : null;
  if (pct !== null && pct > SEUIL_PERIMES_PCT) {
    return { etat: 'en_attente', pctARecalculer: Math.round(pct) };
  }
  if (typeof d.quality_avg !== 'number') {
    return { etat: 'indisponible' };
  }
  return { etat: 'ok', moyenne: d.quality_avg };
}
