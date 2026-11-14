/** Libellés du récapitulatif du feu vert (purs, testables). */
interface Recap { faits: number; total: number; bloquants_restants: number }

/**
 * « 0 point bloquant » serait faux si des points restent sans verdict : on écrit « non évalué ».
 * Un point non fait est normalement bloquant ; un écart entre les deux compteurs signale un point non évalué.
 */
export function libelleBloquants(r: Recap): string {
  const restants = r.total - r.faits;
  if (restants <= 0) return 'Tous les critères sont remplis.';
  if (r.bloquants_restants === 0) return restants > 1 ? `Non évalué : ${restants} points ne sont pas encore évalués` : 'Non évalué : 1 point n’est pas encore évalué';
  return `${r.bloquants_restants} point${r.bloquants_restants > 1 ? 's' : ''} bloquant${r.bloquants_restants > 1 ? 's' : ''} à lever`;
}

/** En démonstration, marquer un point « fait » ne change rien de réel : on le dit. */
export function avertissementDemo(demo: boolean): string | null {
  return demo ? 'Démonstration : marquer un point « fait » n’a aucun effet réel (aucun envoi, aucune vérification).' : null;
}
