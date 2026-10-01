/**
 * PRESSE ET EXCLUSIONS comme critères d'audience (harmonisation des contacts,
 * 2026-09-30).
 *
 * - Le TYPE et la ZONE d'un média ne sont pas des colonnes : ce sont les
 *   étiquettes automatiques `media-type:<code>` et `media-zone:<code>`, que le
 *   serveur vise par `tags` / `contains_any`.
 * - Les EXCLUSIONS de nature et de type de média partent dans le bloc `not`,
 *   à côté de celles de la relation (`criteres-relation.ts`, chantier B, qui
 *   porte le défaut « hors prospection » — une seule liste,
 *   `RelationsProspection` côté serveur).
 *
 * Listes et préfixes viennent du fichier GÉNÉRÉ depuis le serveur
 * (`referentiels.generated.ts`) : aucun slug recopié à la main ici. Jamais une
 * condition vide : une liste vide ne viserait personne (ou, en exclusion, ne
 * retirerait personne sans le dire).
 */
import {
  FORMATS_MEDIA,
  PREFIXE_ETIQUETTE_FORMAT_MEDIA,
  PREFIXE_ETIQUETTE_PUBLIC_MEDIA,
  PREFIXE_ETIQUETTE_THEME_MEDIA,
  PREFIXE_ETIQUETTE_TYPE_MEDIA,
  PREFIXE_ETIQUETTE_ZONE_MEDIA,
  PUBLICS_MEDIA,
  THEMES_MEDIA,
  TYPES_MEDIA,
  ZONES_MEDIA,
} from '@/lib/referentiels.generated';
import type { AudienceCondition, AudienceCriteria } from './AudiencesListPage';

const enPresets = (liste: ReadonlyArray<{ code: string; libelle: string }>) =>
  liste.map((e) => ({ code: e.code, label: e.libelle }));

export const TYPE_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(TYPES_MEDIA);
export const ZONE_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(ZONES_MEDIA);
// Classement des médias (chantier F) : thème, public, format TV — étiquettes
// `media-theme:` / `media-public:` / `media-format:` posées par la lecture du site.
export const THEME_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(THEMES_MEDIA);
export const PUBLIC_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(PUBLICS_MEDIA);
export const FORMAT_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(FORMATS_MEDIA);

export function slugTypeMedia(code: string): string {
  return `${PREFIXE_ETIQUETTE_TYPE_MEDIA}${code}`;
}

export function slugZoneMedia(code: string): string {
  return `${PREFIXE_ETIQUETTE_ZONE_MEDIA}${code}`;
}

/**
 * Les conditions « presse » du bloc `all` : un type parmi ceux choisis ET une
 * zone parmi celles choisies ET (s'ils sont choisis) un thème, un public, un
 * format parmi ceux choisis — une condition par critère, donc un ET entre
 * critères et un OU à l'intérieur de chacun.
 */
export function criteresMedias(
  types: readonly string[],
  zones: readonly string[],
  classement: { themes?: readonly string[]; publics?: readonly string[]; formats?: readonly string[] } = {},
): AudienceCondition[] {
  const conditions: AudienceCondition[] = [];
  const ajouter = (codes: readonly string[] | undefined, prefixe: string) => {
    if (codes !== undefined && codes.length > 0) {
      conditions.push({ field: 'tags', op: 'contains_any', value: codes.map((c) => `${prefixe}${c}`) });
    }
  };
  if (types.length > 0) conditions.push({ field: 'tags', op: 'contains_any', value: types.map(slugTypeMedia) });
  if (zones.length > 0) conditions.push({ field: 'tags', op: 'contains_any', value: zones.map(slugZoneMedia) });
  ajouter(classement.themes, PREFIXE_ETIQUETTE_THEME_MEDIA);
  ajouter(classement.publics, PREFIXE_ETIQUETTE_PUBLIC_MEDIA);
  ajouter(classement.formats, PREFIXE_ETIQUETTE_FORMAT_MEDIA);
  return conditions;
}

/**
 * Les exclusions de nature et de type de média : chaque liste non vide
 * devient UNE condition du bloc `not`.
 */
export function exclusions(choix: { natures: readonly string[]; typesMedia: readonly string[] }): AudienceCondition[] {
  const not: AudienceCondition[] = [];
  if (choix.natures.length > 0) not.push({ field: 'entity_nature', op: 'in', value: [...choix.natures] });
  if (choix.typesMedia.length > 0) {
    not.push({ field: 'tags', op: 'contains_any', value: choix.typesMedia.map(slugTypeMedia) });
  }
  return not;
}

/** Ajoute des exclusions au bloc `not` de critères déjà construits (jamais un `not` vide). */
export function avecExclusions(criteres: AudienceCriteria, not: readonly AudienceCondition[]): AudienceCriteria {
  if (not.length === 0) return criteres;
  return { ...criteres, not: [...(criteres.not ?? []), ...not] };
}
