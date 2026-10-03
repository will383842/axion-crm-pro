/**
 * Critères d'audience en phrases lisibles (audit UX lot 14, 2026-10-03).
 *
 * L'onglet « Critères » d'une audience montrait le JSON brut du DSL serveur
 * (`AudienceBuilderService`). Ce module PUR le traduit en lignes
 * « Département : 69, 38 », « Taille : TPE », « Métier : Experts-comptables ».
 * Un champ inconnu n'est jamais affiché par son nom technique : il devient
 * « Autre critère ».
 *
 * Tous les libellés viennent des référentiels existants (fichier généré depuis
 * le serveur, options des filtres) : aucune nouvelle copie à entretenir.
 */
import {
  FORMATS_MEDIA,
  JOIGNABILITES,
  METIERS,
  NATURES,
  PREFIXE_ETIQUETTE_FORMAT_MEDIA,
  PREFIXE_ETIQUETTE_METIER,
  PREFIXE_ETIQUETTE_PUBLIC_MEDIA,
  PREFIXE_ETIQUETTE_SECTEUR_MEDIA,
  PREFIXE_ETIQUETTE_THEME_MEDIA,
  PREFIXE_ETIQUETTE_TYPE_MEDIA,
  PREFIXE_ETIQUETTE_ZONE_MEDIA,
  PUBLICS_MEDIA,
  REGIONS,
  SECTEURS,
  SECTEURS_MEDIA,
  TAILLES,
  THEMES_MEDIA,
  TYPES_MEDIA,
  ZONES_MEDIA,
  type EntreeReferentiel,
} from '@/lib/referentiels.generated';
import {
  CONFIANCE_EMAIL_OPTIONS,
  PROSPECTION_STATUS_OPTIONS,
  type OptionReferentiel,
} from '@/lib/prospection-referentiels';
import { PRIORITY_OPTIONS } from '@/features/companies/filtresUrl';
import { LIFECYCLE_LABELS, RELATION_TYPE_LABELS } from '@/features/crm-console/types';
import type { AudienceCondition, AudienceCriteria } from './AudiencesListPage';

export interface CritereLisible {
  /** « Doit correspondre », « Au moins un de », « Sauf ». */
  bloc: string;
  libelle: string;
  valeur: string;
}

/** Noms des listes manuelles déjà connus de l'écran (aucun appel ajouté). */
export type NomsDeListes = Readonly<Record<number, string>>;

type Table = Readonly<Record<string, string>>;

const depuisReferentiel = (liste: readonly EntreeReferentiel[]): Table =>
  Object.fromEntries(liste.map((e) => [e.code, e.libelle]));

/** Les options de filtre, sans l'option « tous » (valeur vide). */
const depuisOptions = (liste: readonly OptionReferentiel[]): Table =>
  Object.fromEntries(liste.filter((o) => o.value !== '').map((o) => [o.value, o.label]));

/** Lecture PROPRE d'une table : `constructor` ou `toString` ne sont pas des codes. */
function lire(table: Table | undefined, cle: string): string | undefined {
  return table !== undefined && Object.hasOwn(table, cle) ? table[cle] : undefined;
}

const CHAMPS: Readonly<Record<string, { libelle: string; valeurs?: Table }>> = {
  department_code: { libelle: 'Département' },
  region_code: { libelle: 'Région', valeurs: depuisReferentiel(REGIONS) },
  commune_code: { libelle: 'Commune' },
  country_code: { libelle: 'Pays', valeurs: { FR: 'France' } },
  sector_main: { libelle: 'Secteur', valeurs: depuisReferentiel(SECTEURS) },
  entity_nature: { libelle: "Nature d'organisation", valeurs: depuisReferentiel(NATURES) },
  size_category: { libelle: 'Taille', valeurs: depuisReferentiel(TAILLES) },
  relation_type: { libelle: 'Type de relation', valeurs: RELATION_TYPE_LABELS },
  lifecycle_stage: { libelle: 'Étape', valeurs: LIFECYCLE_LABELS },
  joignabilite: { libelle: 'Joignabilité', valeurs: depuisReferentiel(JOIGNABILITES) },
  prospection_status: { libelle: 'Statut de prospection', valeurs: depuisOptions(PROSPECTION_STATUS_OPTIONS) },
  priority: { libelle: 'Priorité', valeurs: depuisOptions(PRIORITY_OPTIONS) },
  quality_score: { libelle: 'Score qualité' },
  best_email_confidence: { libelle: 'Confiance de l’e-mail', valeurs: depuisOptions(CONFIANCE_EMAIL_OPTIONS) },
  enriched_at: { libelle: 'Date d’enrichissement' },
  has_email: { libelle: 'E-mail connu' },
  tags: { libelle: 'Étiquettes' },
  liste_manuelle: { libelle: 'Liste' },
  segment: { libelle: 'Segment', valeurs: { presse: 'Presse' } },
};

/**
 * Familles d'étiquettes automatiques (`metiers.ts`, `presse.ts`). L'ORDRE
 * compte : le secteur couvert (`media-sujet:secteur-`) doit être reconnu
 * avant le thème (`media-sujet:`).
 */
const FAMILLES_ETIQUETTES: ReadonlyArray<{ prefixe: string; libelle: string; valeurs: Table }> = [
  { prefixe: PREFIXE_ETIQUETTE_METIER, libelle: 'Métier', valeurs: depuisReferentiel(METIERS) },
  { prefixe: PREFIXE_ETIQUETTE_TYPE_MEDIA, libelle: 'Type de média', valeurs: depuisReferentiel(TYPES_MEDIA) },
  { prefixe: PREFIXE_ETIQUETTE_ZONE_MEDIA, libelle: 'Zone de diffusion', valeurs: depuisReferentiel(ZONES_MEDIA) },
  { prefixe: PREFIXE_ETIQUETTE_SECTEUR_MEDIA, libelle: 'Secteur couvert', valeurs: depuisReferentiel(SECTEURS_MEDIA) },
  { prefixe: PREFIXE_ETIQUETTE_THEME_MEDIA, libelle: 'Thème', valeurs: depuisReferentiel(THEMES_MEDIA) },
  { prefixe: PREFIXE_ETIQUETTE_PUBLIC_MEDIA, libelle: 'Public visé', valeurs: depuisReferentiel(PUBLICS_MEDIA) },
  { prefixe: PREFIXE_ETIQUETTE_FORMAT_MEDIA, libelle: 'Format TV', valeurs: depuisReferentiel(FORMATS_MEDIA) },
];

const BLOCS: Record<keyof AudienceCriteria, string> = {
  all: 'Doit correspondre',
  any: 'Au moins un de',
  not: 'Sauf',
};

function familleDe(etiquette: string) {
  return FAMILLES_ETIQUETTES.find((f) => etiquette.startsWith(f.prefixe));
}

/** Une étiquette : libellé du référentiel si elle est automatique, sinon telle quelle. */
function etiquetteLisible(etiquette: string): string {
  const famille = familleDe(etiquette);
  if (famille === undefined) return etiquette;
  const code = etiquette.slice(famille.prefixe.length);
  return lire(famille.valeurs, code) ?? code;
}

function dateLisible(v: string): string {
  const d = new Date(v);
  return Number.isNaN(d.getTime()) ? v : d.toLocaleDateString('fr-FR');
}

function texte(field: string, v: unknown, valeurs: Table | undefined): string {
  if (typeof v === 'boolean') return v ? 'oui' : 'non';
  if (typeof v === 'number') return String(v);
  if (typeof v !== 'string') return '';
  if (field === 'tags') return etiquetteLisible(v);
  if (field === 'enriched_at') return dateLisible(v);
  return lire(valeurs, v) ?? v;
}

function listesLisibles(value: unknown, noms: NomsDeListes): string {
  const ids = (Array.isArray(value) ? value : [value]).map(Number).filter((n) => Number.isFinite(n));
  const nommes: string[] = [];
  let inconnus = 0;
  for (const id of ids) {
    if (Object.hasOwn(noms, id)) nommes.push(noms[id] as string);
    else inconnus += 1;
  }
  if (inconnus > 0) nommes.push(inconnus === 1 ? 'une liste manuelle' : `${inconnus} listes manuelles`);
  return nommes.join(', ');
}

function valeurLisible(c: AudienceCondition, valeurs: Table | undefined, noms: NomsDeListes): string {
  if (c.op === 'is_null') return 'non renseigné';
  if (c.op === 'is_not_null') return 'renseigné';
  const brut =
    c.field === 'liste_manuelle'
      ? listesLisibles(c.value, noms)
      : Array.isArray(c.value)
        ? c.value.map((v) => texte(c.field, v, valeurs)).filter((s) => s !== '').join(', ')
        : texte(c.field, c.value, valeurs);
  const date = c.field === 'enriched_at';
  switch (c.op) {
    case 'gte': return date ? `depuis le ${brut}` : `au moins ${brut}`;
    case 'lte': return date ? `jusqu’au ${brut}` : `au plus ${brut}`;
    case 'gt': return date ? `après le ${brut}` : `plus de ${brut}`;
    case 'lt': return date ? `avant le ${brut}` : `moins de ${brut}`;
    case 'neq':
    case 'not_in': return `tout sauf ${brut}`;
    default: return brut;
  }
}

/** Le libellé d'une condition `tags` : la famille commune, sinon « Étiquettes ». */
function libelleEtiquettes(c: AudienceCondition): string {
  const valeurs = (Array.isArray(c.value) ? c.value : [c.value]).filter((v): v is string => typeof v === 'string');
  const familles = new Set(valeurs.map((v) => familleDe(v)?.libelle ?? null));
  if (familles.size === 1) {
    const [seule] = [...familles];
    if (typeof seule === 'string') return seule;
  }
  return 'Étiquettes';
}

export function criteresLisibles(
  criteres: AudienceCriteria | null | undefined,
  nomsDeListes: NomsDeListes = {},
): CritereLisible[] {
  if (!criteres) return [];
  const lignes: CritereLisible[] = [];
  (Object.keys(BLOCS) as Array<keyof AudienceCriteria>).forEach((cle) => {
    for (const c of criteres[cle] ?? []) {
      const champ = Object.hasOwn(CHAMPS, c.field) ? CHAMPS[c.field] : undefined;
      lignes.push({
        bloc: BLOCS[cle],
        libelle: champ === undefined ? 'Autre critère' : c.field === 'tags' ? libelleEtiquettes(c) : champ.libelle,
        valeur: valeurLisible(c, champ?.valeurs, nomsDeListes),
      });
    }
  });
  return lignes;
}
