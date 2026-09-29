/**
 * Le MÉTIER comme critère d'audience (chantier 2, 2026-09-29).
 *
 * Le métier n'est pas une colonne : c'est l'étiquette automatique
 * `metier-<code>`, posée par le reclassement (`crm:referentiels:reclasser`)
 * et par l'enrichissement depuis la sous-classe NAF rév. 2. Une audience vise
 * donc « les experts-comptables » par une condition `tags` / `contains_any`,
 * que le serveur sait déjà exécuter (`AudienceBuilderService`, champ `tags`).
 *
 * La liste et le préfixe viennent du fichier GÉNÉRÉ depuis le serveur
 * (`referentiels.generated.ts`) : aucun slug n'est recopié à la main ici.
 */
import { METIERS, PREFIXE_ETIQUETTE_METIER } from '@/lib/referentiels.generated';
import type { AudienceCondition } from './AudiencesListPage';

/** Les puces du constructeur : code du métier et libellé lisible. */
export const METIER_PRESETS: ReadonlyArray<{ code: string; label: string }> = METIERS.map((m) => ({
  code: m.code,
  label: m.libelle,
}));

/** Le slug de l'étiquette d'un métier : `metier-` + code. */
export function slugMetier(code: string): string {
  return `${PREFIXE_ETIQUETTE_METIER}${code}`;
}

/**
 * La condition d'audience « porte l'un de ces métiers » — ou `null` quand
 * aucun métier n'est choisi (jamais une condition vide : une liste vide ne
 * viserait personne).
 */
export function critereMetiers(codes: readonly string[]): AudienceCondition | null {
  if (codes.length === 0) return null;
  return { field: 'tags', op: 'contains_any', value: codes.map(slugMetier) };
}
