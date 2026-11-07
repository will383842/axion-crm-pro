/**
 * Une tâche du CRM. Elle vit dans `activities` (type `task`) : il n'existe ni
 * table ni type « crm_tasks ». L'origine dit d'où elle vient : saisie à la
 * main, ou créée par une réponse reçue (Envoi).
 */
export type OrigineTache = 'manuelle' | 'reponse';
export type GenreTache = 'tache' | 'rendez_vous' | 'relance';

export interface Tache {
  id: string;
  titre: string;
  /** Le nom de l'entreprise ou de l'événement concerné. */
  contexte: string;
  origine: OrigineTache;
  genre: GenreTache;
  /** Échéance, AAAA-MM-JJ. */
  echeance: string;
  /** Heure « HH:MM » ; `null` : sans heure. */
  heure: string | null;
  faite: boolean;
  faite_le: string | null;
}

export interface ReponseTaches {
  /** « Aujourd'hui » du serveur, AAAA-MM-JJ. */
  aujourdhui: string;
  data: Tache[];
  /** Réglage « Recevoir mes tâches à 08:00 par e-mail ». */
  rappel_matin: boolean;
}

export interface NouvelleTache {
  titre: string;
  contexte?: string;
  echeance?: string | undefined;
  heure?: string | null | undefined;
}
