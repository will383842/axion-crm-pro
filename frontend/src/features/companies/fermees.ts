/**
 * Entreprises FERMÉES selon l'INSEE (`companies.insee_ferme_le`, posé par la
 * mise à jour mensuelle). Décision du propriétaire du 04/10/2026 : masquées par
 * défaut dans la console, retrouvables par le filtre « Afficher les
 * entreprises fermées » ; la fiche reste accessible par son lien direct.
 * Rien n'est supprimé ni modifié en base.
 */

/** `fermees` de l'adresse et de l'API : absent = masquées. */
export const FERMEES_INCLURE = 'inclure';
export const FERMEES_SEULES = 'seules';

/**
 * « 2026-09-15 » (ou un horodatage ISO) → « 15/09/2026 ». Lu tel quel, sans
 * passer par `Date` : une date seule interprétée en UTC puis affichée à
 * l'heure locale peut reculer d'un jour. `null` si la forme est inattendue.
 */
export function dateFermeture(valeur: string | null | undefined): string | null {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(valeur ?? '');
  return m ? `${m[3]}/${m[2]}/${m[1]}` : null;
}

/** Le texte du bandeau de la fiche. */
export function libelleFermeture(valeur: string | null | undefined): string | null {
  if (!valeur) return null;
  const date = dateFermeture(valeur);
  return date ? `Entreprise fermée selon l’INSEE depuis le ${date}` : 'Entreprise fermée selon l’INSEE';
}
