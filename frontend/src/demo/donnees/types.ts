/**
 * Types du jeu factice de la démo.
 *
 * CRM : formes JSON des écrans existants (`CompanyRowData`, `MediaItem`,
 * `ListeManuelle`, `EmailAudience`, `EvenementResume`…), réduites aux champs
 * que le jeu remplit. Envoi : types GÉNÉRÉS du contrat OpenAPI
 * (`src/features/envoi/api/commun.d.ts`) — une donnée de démo qui ne suit pas
 * le contrat ne compile pas.
 */
import type { components } from '@/features/envoi/api/commun';

type S = components['schemas'];

// ── Socle ─────────────────────────────────────────────────────────────────

export interface Espace {
  id: string;
  name: string;
}

export interface Utilisateur {
  id: string;
  name: string;
  email: string;
  role: 'Propriétaire' | 'Administrateur' | 'Opérateur' | 'Lecteur';
  current_workspace_id: string;
  totp_enabled_at: string;
}

export type Notification = S['Notification'];

// ── CRM ───────────────────────────────────────────────────────────────────

export interface Entreprise {
  id: number;
  siren: string;
  denomination: string;
  naf: string;
  naf_label: string;
  size_category: 'tpe' | 'pme' | 'eti' | 'grand_groupe';
  effectif_range: string;
  city: string;
  postcode: string;
  department_code: string;
  quality_score: number;
  priority: 'haute' | 'moyenne' | 'basse' | 'gelee';
  enriched_at: string | null;
  email_generic: string;
  website: string;
  phone: string;
  etiquettes: string[];
  created_at: string;
}

export interface Contact {
  id: number;
  company_id: number;
  first_name: string;
  last_name: string;
  role: string;
  email: string;
  etiquettes: string[];
  ne_plus_ecrire: boolean;
  created_at: string;
}

export interface Media {
  id: number;
  name: string;
  media_type: string;
  media_family: string;
  periodicity: string | null;
  diffusion_zone: string;
  department_code: string;
  city: string;
  website: string;
  email: string;
  phone: string;
}

export interface Journaliste {
  id: number;
  media_id: number;
  first_name: string;
  last_name: string;
  rubrique: string;
  email: string;
}

export interface Federation {
  id: number;
  siren: string;
  denomination: string;
  famille: string;
  niveau: 'national' | 'regional' | 'departemental';
  department_code: string;
  city: string;
  email_generic: string;
  website: string;
  adherents: number;
}

export interface Evenement {
  id: number;
  nom: string;
  type: string;
  date_debut: string;
  date_fin: string;
  ville: string;
  departement_code: string;
  region: string;
  appel_intervenants: string;
  verifie: boolean;
  participation: string;
  intervention: string;
  organisateur_id: number | null;
}

export interface Audience {
  id: number;
  name: string;
  description: string | null;
  is_active: boolean;
  auto_refresh: boolean;
  member_count: number;
  refreshed_at: string;
  created_at: string;
}

export interface Liste {
  id: number;
  nom: string;
  description: string | null;
  organisations: number;
  personnes: number;
  created_at: string;
  updated_at: string;
}

export interface Etiquette {
  id: number;
  name: string;
  slug: string;
  color: 'bleu' | 'vert' | 'ambre' | 'violet' | 'gris' | 'terracotta';
  usage_count: number;
}

// ── Envoi (types du contrat) ──────────────────────────────────────────────

// TEMPORAIRE (fiche L8-F02) : champs d'ouverture RETIRÉS du contrat, encore lus par
// le jeu factice et ses tests. F02 supprime ces types et le code qui les remplit.
export interface OuvertureHeritee {
  premiere_le: string;
  derniere_le: string;
  nombre: number;
  automatique_probable: boolean;
  raison_automatique: string | null;
}
export interface ResumeOuverturesHerite {
  estimees: number;
  automatiques_probables: number;
  taux_estime: number;
  mention: string;
}
export type Campagne = S['CampagneResume'] & { suivre_ouvertures?: boolean; ouvertures?: ResumeOuverturesHerite | null };
export type Domaine = S['DomaineResume'];
export type Boite = S['BoiteResume'];
export type Serveur = S['ServeurResume'];
export type Destinataire = S['Destinataire'] & { ouverture?: OuvertureHeritee | null };
export type EvenementEnvoi = Omit<S['EvenementEnvoi'], 'type'> & {
  type: S['EvenementEnvoi']['type'] | 'ouverture';
  automatique_probable?: boolean | null;
};
export type Reponse = S['ReponseResume'];
export type MessageType = S['MessageType'];
export type RegleAlerte = S['RegleAlerte'];
export type Desinscription = S['Desinscription'];
export type Opposition = S['Opposition'];
export type EtatEnvois = S['EtatEnvois'];

// ── Le jeu complet ────────────────────────────────────────────────────────

export interface JeuDemo {
  espaces: Espace[];
  utilisateurs: Utilisateur[];
  notifications: Notification[];
  entreprises: Entreprise[];
  contacts: Contact[];
  medias: Media[];
  journalistes: Journaliste[];
  federations: Federation[];
  evenements: Evenement[];
  audiences: Audience[];
  listes: Liste[];
  etiquettes: Etiquette[];
  campagnes: Campagne[];
  domaines: Domaine[];
  boites: Boite[];
  serveurs: Serveur[];
  destinataires: Destinataire[];
  evenementsEnvoi: EvenementEnvoi[];
  reponses: Reponse[];
  messagesTypes: MessageType[];
  reglesAlerte: RegleAlerte[];
  desinscriptions: Desinscription[];
  opposition: Opposition[];
  etatEnvois: EtatEnvois;
}

/** Les collections (tableaux) du jeu ; `etatEnvois` est un objet unique. */
export type NomCollection = { [K in keyof JeuDemo]: JeuDemo[K] extends unknown[] ? K : never }[keyof JeuDemo];
