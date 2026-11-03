import type { Tache } from './types';

export interface GroupesTaches {
  enRetard: Tache[];
  aujourdhui: Tache[];
  semaine: Tache[];
  faites: Tache[];
}

/** Ajoute `jours` à une date AAAA-MM-JJ (calcul en UTC : aucune dépendance au fuseau du navigateur). */
export function ajouterJours(date: string, jours: number): string {
  const d = new Date(`${date}T00:00:00Z`);
  d.setUTCDate(d.getUTCDate() + jours);
  return d.toISOString().slice(0, 10);
}

/** Dimanche de la semaine de `date` (la semaine court du lundi au dimanche). */
export function finDeSemaine(date: string): string {
  const jour = new Date(`${date}T00:00:00Z`).getUTCDay(); // 0 = dimanche
  return ajouterJours(date, jour === 0 ? 0 : 7 - jour);
}

function parEcheance(a: Tache, b: Tache): number {
  return `${a.echeance} ${a.heure ?? '99:99'}`.localeCompare(`${b.echeance} ${b.heure ?? '99:99'}`);
}

/**
 * Range les tâches selon le « aujourd'hui » donné par le serveur. Les tâches
 * faites vont à part ; plus tard que dimanche, une tâche n'est pas affichée.
 */
export function grouper(taches: readonly Tache[], aujourdhui: string): GroupesTaches {
  const dimanche = finDeSemaine(aujourdhui);
  const ouvertes = taches.filter((t) => !t.faite);
  return {
    enRetard: ouvertes.filter((t) => t.echeance < aujourdhui).sort(parEcheance),
    aujourdhui: ouvertes.filter((t) => t.echeance === aujourdhui).sort(parEcheance),
    semaine: ouvertes.filter((t) => t.echeance > aujourdhui && t.echeance <= dimanche).sort(parEcheance),
    faites: taches.filter((t) => t.faite).sort((a, b) => (b.faite_le ?? '').localeCompare(a.faite_le ?? '')),
  };
}

const JOURS = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'] as const;
const MOIS = ['janv.', 'févr.', 'mars', 'avr.', 'mai', 'juin', 'juil.', 'août', 'sept.', 'oct.', 'nov.', 'déc.'] as const;

/** « Mardi 3 nov. » — la date d'une échéance, en français, sans passer par le fuseau du navigateur. */
export function dateCourte(date: string): string {
  const d = new Date(`${date}T00:00:00Z`);
  const jour = JOURS[d.getUTCDay()] ?? '';
  return `${jour.charAt(0).toUpperCase()}${jour.slice(1)} ${d.getUTCDate()} ${MOIS[d.getUTCMonth()] ?? ''}`;
}
