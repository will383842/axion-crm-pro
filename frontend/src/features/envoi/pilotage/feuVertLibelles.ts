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

/** Code du point qui décrit le drapeau d'exploitation : le feu vert ne le touche jamais, le serveur l'exclut. */
export const POINT_DRAPEAU = 'auto.reel_actif_faux';

interface PointBloquant { code: string; bloquant: boolean; fait: boolean }

/** Nombre de points bloquants rouges qui empêchent « Accorder » (le serveur refuse 409 sinon). */
export function bloquantsRouges(points: ReadonlyArray<PointBloquant>): number {
  return points.filter((p) => p.bloquant && !p.fait && p.code !== POINT_DRAPEAU).length;
}

export function libelleAccord(n: number): string {
  if (n === 0) return 'Aucun point bloquant : l’accord est possible.';
  return `${n} point${n > 1 ? 's' : ''} bloquant${n > 1 ? 's' : ''} à lever avant d’accorder.`;
}

/** Rappel affiché à côté des deux gestes : le feu vert est nécessaire, pas suffisant. */
export const RAPPEL_ENVOI_REEL = 'Accorder le feu vert n’active pas l’envoi réel : l’envoi réel reste une décision d’exploitation, prise hors de cet écran.';

export const AIDE_MOTIF = 'Le motif compte de 10 à 500 caractères. N’écrivez JAMAIS d’adresse e-mail.';
