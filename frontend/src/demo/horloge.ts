/**
 * Horloge de la démo : « aujourd'hui » est FIXE.
 *
 * Mardi 12 janvier 2027, 09:30 à Paris (08:30 UTC, heure d'hiver). Les données
 * couvrent les 30 jours d'avant : les graphiques sont toujours pleins, quelle
 * que soit la date réelle de la présentation. Jamais `new Date()` sans argument
 * dans une donnée de démo : utilisez `maintenant()` et les aides ci-dessous.
 */

export const MAINTENANT_ISO = '2027-01-12T08:30:00.000Z';
export const FUSEAU_DEMO = 'Europe/Paris';
export const JOURS_HISTORIQUE = 30;

const MAINTENANT_MS = Date.parse(MAINTENANT_ISO);
const MINUTE = 60_000;
const HEURE = 60 * MINUTE;
const JOUR = 24 * HEURE;

/** L'instant « maintenant » de la démo (un objet neuf à chaque appel). */
export function maintenant(): Date {
  return new Date(MAINTENANT_MS);
}

/** ISO d'un instant décalé de maintenant (négatif = passé). */
export function decalage({ jours = 0, heures = 0, minutes = 0 }: { jours?: number; heures?: number; minutes?: number }): string {
  return new Date(MAINTENANT_MS + jours * JOUR + heures * HEURE + minutes * MINUTE).toISOString();
}

/** ISO d'un instant il y a `jours` jours, à l'heure de Paris donnée (`hh`, `mm`). */
export function ilYa(jours: number, hh = 9, mm = 0): string {
  // Heure d'hiver à Paris en janvier et décembre : UTC + 1.
  const debutJourUtc = Date.parse('2027-01-12T00:00:00.000Z') - jours * JOUR;
  return new Date(debutJourUtc + (hh - 1) * HEURE + mm * MINUTE).toISOString();
}

/** Date courte AAAA-MM-JJ d'un jour passé. */
export function jourIso(jours: number): string {
  return ilYa(jours, 12).slice(0, 10);
}
