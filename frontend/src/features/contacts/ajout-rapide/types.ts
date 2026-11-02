/**
 * Types communs aux contacts de la démo (DM1-CON) : fiche, doublon, import.
 *
 * Ils vivent ici et non dans `crm-console/types.ts` (fichier partagé, interdit
 * à ce lot). Les libellés français n'ont qu'une source : ce fichier.
 */
import type { LifecycleStage, RelationType } from '@/features/crm-console/types';

/** D'où vient la fiche : obligatoire, sans quoi on ne peut ni la justifier ni la rectifier. */
export const PROVENANCES = [
  { valeur: 'salon', libelle: 'Salon ou événement' },
  { valeur: 'rencontre', libelle: 'Rencontre en personne' },
  { valeur: 'site', libelle: 'Formulaire du site' },
  { valeur: 'recommandation', libelle: 'Recommandation' },
  { valeur: 'fichier-client', libelle: 'Fichier client' },
  { valeur: 'autre', libelle: 'Autre (à préciser en note)' },
] as const;

export type Provenance = (typeof PROVENANCES)[number]['valeur'];

export function libelleProvenance(valeur: string): string {
  return PROVENANCES.find((p) => p.valeur === valeur)?.libelle ?? valeur;
}

/** Une fiche contact telle que la démo la garde. */
export interface FicheContact {
  id: number;
  company_id: number;
  first_name: string;
  last_name: string;
  email: string;
  fonction: string;
  organisation: string;
  relation_type: RelationType;
  lifecycle_stage: LifecycleStage;
  provenance: string;
  person_key: string;
  /** Non nul : la fiche est dans la corbeille (restaurable, jamais supprimée). */
  retire_le: string | null;
  ne_plus_ecrire: boolean;
  verifie_il_y_a_jours: number;
  rappel_le: string | null;
  rappel_note: string | null;
  created_at: string;
}

export interface DoublonPropose {
  id: number;
  person_key: string;
  nom_complet: string;
  email: string;
  organisation: string;
  raison: 'meme_email' | 'meme_nom';
}

export interface DoublonsReponse {
  data: DoublonPropose[];
}

export interface NouveauContact {
  first_name: string;
  last_name: string;
  email: string;
  organisation: string;
  fonction?: string;
  provenance: string;
}

export interface AjoutReponse {
  resultat: 'cree' | 'fusionne';
  fiche: FicheContact;
}

// ── Import ────────────────────────────────────────────────────────────────

export type ChampImport = 'prenom' | 'nom' | 'email' | 'organisation' | 'fonction' | 'provenance' | 'ignorer';

export const CHAMPS_IMPORT: ReadonlyArray<{ valeur: ChampImport; libelle: string }> = [
  { valeur: 'prenom', libelle: 'Prénom' },
  { valeur: 'nom', libelle: 'Nom' },
  { valeur: 'email', libelle: 'E-mail' },
  { valeur: 'organisation', libelle: 'Organisation' },
  { valeur: 'fonction', libelle: 'Fonction' },
  { valeur: 'provenance', libelle: 'Provenance' },
  { valeur: 'ignorer', libelle: 'Ne pas importer' },
];

export interface LigneImport {
  ligne: number;
  prenom: string;
  nom: string;
  email: string;
  organisation: string;
  fonction: string;
  provenance: string;
}

export interface LigneRefusee {
  ligne: number;
  raison: string;
}

export interface LigneDoublon {
  ligne: number;
  existant: string;
}

export interface BilanImport {
  total: number;
  a_creer: number;
  refusees: LigneRefusee[];
  doublons: LigneDoublon[];
  sans_provenance: number[];
}

export interface RapportImport extends BilanImport {
  crees: number;
}

export interface TravailImport {
  id: string;
  statut: 'en_cours' | 'termine';
  total: number;
  traitees: number;
  rapport: RapportImport | null;
}

// ── Fiche d'une personne ──────────────────────────────────────────────────

export type VerdictEcrire = 'oui' | 'non' | 'prudence';

export interface DroitEcrire {
  verdict: VerdictEcrire;
  raison: string;
}

export type EtatEnvoi = 'en_file' | 'parti' | 'refuse' | 'repondu' | 'annule';

export const ETAT_ENVOI_LIBELLES: Record<EtatEnvoi, string> = {
  en_file: 'En file',
  parti: 'Parti',
  refuse: 'Refusé',
  repondu: 'Répondu',
  annule: 'Annulé',
};

export interface EnvoiFiche {
  id: string;
  message: string;
  etat: EtatEnvoi;
  raison: string;
  le: string | null;
}

/** Ce que la fiche ajoute à la chronologie d'une personne (champ `fiche` de la réponse). */
export interface FicheDetail {
  contact_id: number;
  first_name: string;
  last_name: string;
  email: string;
  fonction: string;
  organisation: string;
  etape: LifecycleStage;
  provenance: string;
  retire_le: string | null;
  droit_ecrire: DroitEcrire;
  envois: EnvoiFiche[];
  rappel_le: string | null;
  rappel_note: string | null;
}

export interface CorbeilleLigne {
  id: number;
  person_key: string;
  first_name: string;
  last_name: string;
  email: string;
  organisation: string;
  retire_le: string;
}

/** `2027-01-12T08:30:00Z` → `12/01/2027` (fuseau de Paris). */
export function dateFr(iso: string | null | undefined): string {
  if (iso === null || iso === undefined) return '—';
  const brut = iso.length === 10 ? `${iso}T12:00:00Z` : iso;
  const d = new Date(brut);
  if (Number.isNaN(d.getTime())) return iso;
  return new Intl.DateTimeFormat('fr-FR', { day: '2-digit', month: '2-digit', year: 'numeric', timeZone: 'Europe/Paris' }).format(d);
}

/** Le message en français qu'explique le serveur (422, 409), sinon `null`. */
export function messageServeur(err: unknown): string | null {
  if (typeof err === 'object' && err !== null) {
    const e = err as { response?: { data?: { message?: string } } };
    return e.response?.data?.message ?? null;
  }
  return null;
}

/** Les erreurs de champ renvoyées par le serveur (`details`), à plat. */
export function erreursDeChamps(err: unknown): string[] {
  const details = (err as { response?: { data?: { details?: Record<string, string[]> } } }).response?.data?.details;
  return details === undefined ? [] : Object.values(details).flat();
}
