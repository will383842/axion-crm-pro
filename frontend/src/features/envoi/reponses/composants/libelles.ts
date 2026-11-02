/** Libellés, tons et mise en forme communs aux écrans des réponses (Envoi). */
import type { components } from '@/features/envoi/api/commun';
import type { StatusTone } from '@/components/ui';

export type Reponse = components['schemas']['ReponseResume'];
export type Classement = components['schemas']['ClassementReponse'];

export const CLASSEMENTS: Record<Classement, { libelle: string; ton: StatusTone }> = {
  interesse: { libelle: 'Intéressé', ton: 'success' },
  pas_maintenant: { libelle: 'Plus tard', ton: 'info' },
  question: { libelle: 'Question', ton: 'info' },
  refus: { libelle: 'Pas intéressé', ton: 'neutral' },
  stop: { libelle: 'STOP', ton: 'danger' },
  absence: { libelle: 'Absent', ton: 'warning' },
  a_rattacher: { libelle: 'À rattacher', ton: 'pending' },
};

/** Ce que fait « Appliquer la suggestion », par classement (phrase affichée avant le geste). */
export const EFFET_SUGGESTION: Record<Classement, string> = {
  interesse: 'Créer une tâche dans le CRM, poser l’étape « répondu » et arrêter la séquence.',
  question: 'Créer une tâche « répondre » dans le CRM, poser l’étape « répondu » et arrêter la séquence.',
  pas_maintenant: 'Créer une tâche pour recontacter plus tard, poser l’étape « répondu » et arrêter la séquence.',
  refus: 'Poser l’étape « répondu » et arrêter la séquence. Aucune tâche.',
  absence: 'Poser l’étape « répondu » et reprendre la séquence au retour de la personne.',
  stop: 'Mettre la personne à l’écart des envois (ses données sont conservées).',
  a_rattacher: 'Rattacher la réponse à une fiche.',
};

/** Les classements qui créent une tâche dans le CRM. */
export const CLASSEMENTS_AVEC_TACHE: readonly Classement[] = ['interesse', 'question', 'pas_maintenant'];

export function nomComplet(r: Pick<Reponse, 'prenom' | 'nom' | 'de'>): string {
  const nom = [r.prenom, r.nom].filter((x): x is string => typeof x === 'string' && x !== '').join(' ');
  return nom === '' ? r.de : nom;
}

export function initiales(r: Pick<Reponse, 'prenom' | 'nom' | 'de'>): string {
  const lettres = [r.prenom, r.nom].filter((x): x is string => typeof x === 'string' && x !== '').map((x) => x.charAt(0));
  return (lettres.length > 0 ? lettres.join('') : r.de.charAt(0)).toUpperCase();
}

const FORMAT_DATE = new Intl.DateTimeFormat('fr-FR', {
  day: 'numeric',
  month: 'short',
  hour: '2-digit',
  minute: '2-digit',
  timeZone: 'Europe/Paris',
});

export function formaterDate(iso: string): string {
  return FORMAT_DATE.format(new Date(iso)).replace(',', '');
}
