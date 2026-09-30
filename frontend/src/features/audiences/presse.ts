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
  PREFIXE_ETIQUETTE_TYPE_MEDIA,
  PREFIXE_ETIQUETTE_ZONE_MEDIA,
  TYPES_MEDIA,
  ZONES_MEDIA,
} from '@/lib/referentiels.generated';
import type { AudienceCondition, AudienceCriteria } from './AudiencesListPage';

const enPresets = (liste: ReadonlyArray<{ code: string; libelle: string }>) =>
  liste.map((e) => ({ code: e.code, label: e.libelle }));

export const TYPE_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(TYPES_MEDIA);
export const ZONE_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(ZONES_MEDIA);

export function slugTypeMedia(code: string): string {
  return `${PREFIXE_ETIQUETTE_TYPE_MEDIA}${code}`;
}

export function slugZoneMedia(code: string): string {
  return `${PREFIXE_ETIQUETTE_ZONE_MEDIA}${code}`;
}

/**
 * Les conditions « presse » du bloc `all` : un type parmi ceux choisis ET une
 * zone parmi celles choisies (deux conditions distinctes, donc un ET).
 */
export function criteresMedias(types: readonly string[], zones: readonly string[]): AudienceCondition[] {
  const conditions: AudienceCondition[] = [];
  if (types.length > 0) conditions.push({ field: 'tags', op: 'contains_any', value: types.map(slugTypeMedia) });
  if (zones.length > 0) conditions.push({ field: 'tags', op: 'contains_any', value: zones.map(slugZoneMedia) });
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
