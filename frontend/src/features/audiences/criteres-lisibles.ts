/**
 * Critères d'audience en phrases lisibles (audit UX lot 14, 2026-10-03).
 *
 * L'onglet « Critères » d'une audience montrait le JSON brut du DSL serveur.
 * Ce module PUR le traduit en lignes « Département : 69, 38 », « Taille : TPE ».
 * Un champ inconnu n'est jamais affiché par son nom technique : il devient
 * « Autre critère ».
 */
import {
  JOIGNABILITES,
  NATURES,
  REGIONS,
  SECTEURS,
  TAILLES,
  type EntreeReferentiel,
} from '@/lib/referentiels.generated';
import { LIFECYCLE_LABELS, RELATION_TYPE_LABELS } from '@/features/crm-console/types';
import type { AudienceCondition, AudienceCriteria } from './AudiencesListPage';

export interface CritereLisible {
  /** « Doit correspondre », « Au moins un de », « Sauf ». */
  bloc: string;
  libelle: string;
  valeur: string;
}

const versTable = (liste: readonly EntreeReferentiel[]): Record<string, string> =>
  Object.fromEntries(liste.map((e) => [e.code, e.libelle]));

const STATUTS: Record<string, string> = {
  pending: 'À compléter',
  ready_for_outreach: 'Prêt pour la prospection',
  partial_email: 'E-mail partiel',
  archived_no_email: 'Archivé sans e-mail',
};

const CHAMPS: Record<string, { libelle: string; valeurs?: Record<string, string> }> = {
  department_code: { libelle: 'Département' },
  region_code: { libelle: 'Région', valeurs: versTable(REGIONS) },
  country_code: { libelle: 'Pays', valeurs: { FR: 'France' } },
  sector_main: { libelle: 'Secteur', valeurs: versTable(SECTEURS) },
  entity_nature: { libelle: "Nature d'organisation", valeurs: versTable(NATURES) },
  size_category: { libelle: 'Taille', valeurs: versTable(TAILLES) },
  relation_type: { libelle: 'Type de relation', valeurs: RELATION_TYPE_LABELS },
  lifecycle_stage: { libelle: 'Étape', valeurs: LIFECYCLE_LABELS },
  joignabilite: { libelle: 'Joignabilité', valeurs: versTable(JOIGNABILITES) },
  prospection_status: { libelle: 'Statut de prospection', valeurs: STATUTS },
  quality_score: { libelle: 'Score qualité' },
  has_email: { libelle: 'E-mail connu' },
  tags: { libelle: 'Étiquettes' },
  liste_manuelle: { libelle: 'Liste' },
  segment: { libelle: 'Segment' },
};

const BLOCS: Record<keyof AudienceCriteria, string> = {
  all: 'Doit correspondre',
  any: 'Au moins un de',
  not: 'Sauf',
};

function texte(v: unknown, valeurs?: Record<string, string>): string {
  if (typeof v === 'boolean') return v ? 'oui' : 'non';
  if (typeof v === 'string') return valeurs?.[v] ?? v;
  if (typeof v === 'number') return String(v);
  return '';
}

function valeurLisible(c: AudienceCondition, valeurs?: Record<string, string>): string {
  if (c.op === 'is_null') return 'non renseigné';
  if (c.op === 'not_null') return 'renseigné';
  const brut = Array.isArray(c.value)
    ? c.value.map((v) => texte(v, valeurs)).filter((s) => s !== '').join(', ')
    : texte(c.value, valeurs);
  switch (c.op) {
    case 'gte': return `au moins ${brut}`;
    case 'lte': return `au plus ${brut}`;
    case 'gt': return `plus de ${brut}`;
    case 'lt': return `moins de ${brut}`;
    case 'neq':
    case 'not_in': return `tout sauf ${brut}`;
    default: return brut;
  }
}

export function criteresLisibles(criteres: AudienceCriteria | null | undefined): CritereLisible[] {
  if (!criteres) return [];
  const lignes: CritereLisible[] = [];
  (Object.keys(BLOCS) as Array<keyof AudienceCriteria>).forEach((cle) => {
    for (const c of criteres[cle] ?? []) {
      const champ = CHAMPS[c.field];
      lignes.push({
        bloc: BLOCS[cle],
        libelle: champ?.libelle ?? 'Autre critère',
        valeur: valeurLisible(c, champ?.valeurs),
      });
    }
  });
  return lignes;
}
