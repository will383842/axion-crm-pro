/**
 * Contrats de la console CRM v2 — miroir des projections explicites des
 * contrôleurs `App\Http\Controllers\Api\Crm\*`.
 *
 * Les libellés FR vivent ici et NULLE PART ailleurs : le lexique de la
 * conception §4.3 (« Type », « Étape », jamais `relation_type` à l'écran) n'est
 * tenable que s'il a une seule source. Un slug technique qui atteint l'écran,
 * c'est toujours un endroit où on a re-mappé à la main.
 */

export type RelationType =
  | 'prospect'
  | 'client'
  | 'presse_media'
  | 'partenaire'
  | 'investisseur'
  | 'conference'
  | 'newsletter'
  | 'fournisseur';

export type LifecycleStage = 'nouveau' | 'qualifie' | 'opportunite' | 'client' | 'dormant' | 'perdu';

export type CandidateFamily = 'candidat_commercial' | 'candidat_video' | 'candidat_tech' | 'candidat_autre';

export type CandidateStage = 'nouveau' | 'preselection' | 'entretien' | 'retenu' | 'vivier' | 'refuse';

export const RELATION_TYPE_LABELS: Record<RelationType, string> = {
  prospect: 'Prospects',
  client: 'Clients',
  presse_media: 'Presse & médias',
  partenaire: 'Partenaires',
  investisseur: 'Investisseurs',
  conference: 'Conférences',
  newsletter: 'Newsletter',
  fournisseur: 'Fournisseurs',
};

export const LIFECYCLE_LABELS: Record<LifecycleStage, string> = {
  nouveau: 'Nouveau',
  qualifie: 'Qualifié',
  opportunite: 'Opportunité',
  client: 'Client',
  dormant: 'Dormant',
  perdu: 'Perdu',
};

export const CANDIDATE_FAMILY_LABELS: Record<CandidateFamily, string> = {
  candidat_commercial: 'Commercial',
  candidat_video: 'Vidéo',
  candidat_tech: 'Tech',
  candidat_autre: 'Autres métiers',
};

export const CANDIDATE_STAGE_LABELS: Record<CandidateStage, string> = {
  nouveau: 'Nouveau',
  preselection: 'Présélection',
  entretien: 'Entretien',
  retenu: 'Retenu',
  vivier: 'Vivier',
  refuse: 'Refusé',
};

export const RELATION_TYPES = Object.keys(RELATION_TYPE_LABELS) as RelationType[];
export const CANDIDATE_FAMILIES = Object.keys(CANDIDATE_FAMILY_LABELS) as CandidateFamily[];

export interface HubContact {
  id: number;
  first_name: string | null;
  last_name: string;
  email: string | null;
  phone: string | null;
  person_key: string | null;
}

export interface HubCompany {
  id: number;
  siren: string | null;
  denomination: string | null;
  relation_type: RelationType;
  lifecycle_stage: LifecycleStage;
  legal_basis: string | null;
  city_name: string | null;
  department_code: string | null;
  size_category: string | null;
  email_generic: string | null;
  updated_at: string | null;
  tags: string[];
  contacts: HubContact[];
}

export interface CursorMeta {
  per_page: number;
  next_cursor: string | null;
  prev_cursor: string | null;
  has_more: boolean;
}

export interface CursorResponse<T> {
  data: T[];
  meta: CursorMeta;
}

export interface CountsResponse {
  total: number;
  by_relation_type: Record<string, number>;
  by_lifecycle_stage: Record<string, number>;
  /**
   * Compteurs du hub uniquement (pas ceux du vivier) : ils sont servis depuis un
   * cache court côté serveur — le calcul balayait `companies` en entier, mesuré
   * à ~3 s sur les 4,29 M de fiches de la production. `computed_at` est en temps
   * universel (§29 n°16) et `fresh_for_seconds` dit la largeur de la fenêtre de
   * fraîcheur, pour qu'un écran puisse afficher « chiffres arrêtés à … » plutôt
   * que de laisser croire à un total à la seconde près.
   */
  computed_at?: string;
  fresh_for_seconds?: number;
}

export interface CandidateRow {
  id: number;
  first_name: string | null;
  last_name: string;
  email: string | null;
  phone: string | null;
  relation_type: CandidateFamily;
  lifecycle_stage: CandidateStage;
  offer_slug: string | null;
  source: string | null;
  consent_version: string | null;
  consent_vivier_at: string | null;
  derniere_interaction_at: string | null;
  purge_prevue_le: string | null;
  cv_ref: string | null;
  opt_out: boolean;
  person_key: string | null;
  tags: string[];
}

export interface PendingMatch {
  denomination?: string;
  postcode?: string;
  city?: string;
  website?: string;
  email?: string;
  first_name?: string;
  last_name?: string;
  phone?: string;
}

export interface ArbitrageRow {
  activity_id: number;
  kind: string | null;
  title: string | null;
  occurred_at: string | null;
  external_ref: string | null;
  person_key: string | null;
  pending_match: PendingMatch;
}

export interface ArbitrageResponse {
  data: ArbitrageRow[];
  meta: { total: number; per_page: number };
}

export interface TimelineEntry {
  id: number;
  universe: 'business' | 'vivier';
  kind: string | null;
  title: string | null;
  occurred_at: string | null;
  external_ref: string | null;
  subject_type: string | null;
  subject_id: number | null;
}

export interface TimelineSubject {
  universe: 'business' | 'vivier';
  type: 'contact' | 'candidate';
  id: number;
  first_name: string | null;
  last_name: string | null;
  email: string | null;
  company?: { id: number; denomination: string | null; siren: string | null } | null;
}

export interface TimelineResponse {
  person_key: string;
  universes: {
    business: { accessible: boolean; exists: boolean };
    vivier: { accessible: boolean; exists: boolean };
  };
  subjects: TimelineSubject[];
  data: TimelineEntry[];
}

/** Libellé humain d'un tag gouverné : `sect:btp` → « Secteur · btp ». */
export function tagLabel(slug: string): string {
  const [namespace, value] = slug.split(':');
  if (value === undefined) return slug;

  const namespaces: Record<string, string> = {
    sect: 'Secteur',
    taille: 'Taille',
    geo: 'Zone',
    svc: 'Intérêt',
    src: 'Source',
    'cand-offre': 'Offre',
    'cand-b2b': 'B2B',
    'cand-ia': 'IA',
    'cand-zone': 'Zone',
    'cand-dispo': 'Dispo',
    'cand-mobilite': 'Mobilité',
  };

  return `${namespaces[namespace ?? ''] ?? namespace ?? ''} · ${value}`;
}
