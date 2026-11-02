/**
 * Calculs de l'assistant : dates à l'heure de Paris, jours ouvrés, rythme d'envoi.
 * Fonctions pures, sans horloge cachée : l'instant « maintenant » vient du serveur.
 */

export const FUSEAU = 'Europe/Paris';
const JOURS_COURTS = ['dim', 'lun', 'mar', 'mer', 'jeu', 'ven', 'sam'] as const;
export type JourCourt = (typeof JOURS_COURTS)[number];

/** `AAAA-MM-JJ` d'un instant, à l'heure de Paris. */
export function jourDeParis(instant: Date): string {
  const p = new Intl.DateTimeFormat('fr-CA', { timeZone: FUSEAU, year: 'numeric', month: '2-digit', day: '2-digit' }).format(instant);
  return p;
}

/** Ajoute des jours calendaires à un `AAAA-MM-JJ`. */
export function ajouterJours(jour: string, n: number): string {
  const [a, m, j] = jour.split('-').map(Number) as [number, number, number];
  const d = new Date(Date.UTC(a, m - 1, j + n));
  return d.toISOString().slice(0, 10);
}

export function estOuvre(jour: string): boolean {
  const [a, m, j] = jour.split('-').map(Number) as [number, number, number];
  const n = new Date(Date.UTC(a, m - 1, j)).getUTCDay();
  return n !== 0 && n !== 6;
}

export function nomDuJour(jour: string): JourCourt {
  const [a, m, j] = jour.split('-').map(Number) as [number, number, number];
  return JOURS_COURTS[new Date(Date.UTC(a, m - 1, j)).getUTCDay()]!;
}

/** Date et heure de Paris (`AAAA-MM-JJ`, `HH:MM`) vers un instant ISO (UTC). */
export function parisVersIso(jour: string, heure: string): string {
  const [a, m, j] = jour.split('-').map(Number) as [number, number, number];
  const [h, mi] = heure.split(':').map(Number) as [number, number];
  const devine = Date.UTC(a, m - 1, j, h, mi);
  const decalage = (instantMs: number): number => {
    const p = new Intl.DateTimeFormat('en-GB', { timeZone: FUSEAU, hourCycle: 'h23', year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit' }).formatToParts(new Date(instantMs));
    const v = (t: string) => Number(p.find((x) => x.type === t)?.value ?? 0);
    return Date.UTC(v('year'), v('month') - 1, v('day'), v('hour'), v('minute')) - instantMs;
  };
  return new Date(devine - decalage(devine - decalage(devine))).toISOString();
}

/** Nombre de jours ouvrés pour envoyer `restant` messages à `plafond` par jour. */
export function joursNecessaires(restant: number, plafond: number): number {
  if (plafond <= 0 || restant <= 0) return 0;
  return Math.ceil(restant / plafond);
}

/** Dernier jour d'envoi : le jour de départ compte pour le premier jour ouvré. */
export function dateDeFin(depart: string, jours: number): string {
  let courant = depart;
  while (!estOuvre(courant)) courant = ajouterJours(courant, 1);
  let restants = Math.max(1, jours) - 1;
  while (restants > 0) {
    courant = ajouterJours(courant, 1);
    if (estOuvre(courant)) restants -= 1;
  }
  return courant;
}

export function dateCourte(jour: string): string {
  const [a, m, j] = jour.split('-').map(Number) as [number, number, number];
  return new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', day: 'numeric', month: 'short' }).format(new Date(Date.UTC(a, m - 1, j)));
}

export function dateLongue(jour: string): string {
  const [a, m, j] = jour.split('-').map(Number) as [number, number, number];
  return new Intl.DateTimeFormat('fr-FR', { timeZone: 'UTC', weekday: 'short', day: 'numeric', month: 'short' }).format(new Date(Date.UTC(a, m - 1, j)));
}

export function heureDeParis(iso: string): string {
  return new Intl.DateTimeFormat('fr-FR', { timeZone: FUSEAU, hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

/** « 1 personne », « 388 personnes ». */
export function pluriel(n: number, un: string, plusieurs: string): string {
  return `${n.toLocaleString('fr-FR')} ${n > 1 ? plusieurs : un}`;
}

export const FENETRES = [
  { id: '09-17', libelle: '09:00 – 17:00, jours ouvrés', de: '09:00', a: '17:00' },
  { id: '10-16', libelle: '10:00 – 16:00, jours ouvrés', de: '10:00', a: '16:00' },
] as const;
