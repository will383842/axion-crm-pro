/**
 * RELATION, PRESSE ET EXCLUSIONS comme critères d'audience (harmonisation des
 * contacts, 2026-09-30).
 *
 * - La RELATION est une colonne (`companies.relation_type`) : condition
 *   `relation_type` / `in`.
 * - Le TYPE et la ZONE d'un média ne sont pas des colonnes : ce sont les
 *   étiquettes automatiques `media-type:<code>` et `media-zone:<code>`, que le
 *   serveur vise par `tags` / `contains_any`.
 * - Les EXCLUSIONS partent dans le bloc `not` : une fiche est retirée dès
 *   qu'UNE condition d'exclusion la vise. Une campagne de prospection exclut
 *   la presse et les clients (`RELATIONS_HORS_PROSPECTION`).
 *
 * Listes et préfixes viennent du fichier GÉNÉRÉ depuis le serveur
 * (`referentiels.generated.ts`) : aucun slug recopié à la main ici. Jamais une
 * condition vide : une liste vide ne viserait personne (ou, en exclusion, ne
 * retirerait personne sans le dire).
 */
import {
  PREFIXE_ETIQUETTE_TYPE_MEDIA,
  PREFIXE_ETIQUETTE_ZONE_MEDIA,
  RELATIONS,
  RELATIONS_HORS_PROSPECTION,
  TYPES_MEDIA,
  ZONES_MEDIA,
} from '@/lib/referentiels.generated';
import type { AudienceCondition } from './AudiencesListPage';

const enPresets = (liste: ReadonlyArray<{ code: string; libelle: string }>) =>
  liste.map((e) => ({ code: e.code, label: e.libelle }));

export const RELATION_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(RELATIONS);
export const TYPE_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(TYPES_MEDIA);
export const ZONE_MEDIA_PRESETS: ReadonlyArray<{ code: string; label: string }> = enPresets(ZONES_MEDIA);

/** Le préréglage « prospection » : exclure la presse et les clients. */
export const EXCLUSIONS_PROSPECTION: readonly string[] = [...RELATIONS_HORS_PROSPECTION];

export function slugTypeMedia(code: string): string {
  return `${PREFIXE_ETIQUETTE_TYPE_MEDIA}${code}`;
}

export function slugZoneMedia(code: string): string {
  return `${PREFIXE_ETIQUETTE_ZONE_MEDIA}${code}`;
}

/** « a l'une de ces relations » — ou `null` si rien n'est choisi. */
export function critereRelations(codes: readonly string[]): AudienceCondition | null {
  if (codes.length === 0) return null;
  return { field: 'relation_type', op: 'in', value: [...codes] };
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
 * Le bloc `not` : chaque liste non vide devient UNE condition, et la fiche est
 * retirée dès qu'une d'elles la vise.
 */
export function exclusions(choix: {
  relations: readonly string[];
  natures: readonly string[];
  typesMedia: readonly string[];
}): AudienceCondition[] {
  const not: AudienceCondition[] = [];
  if (choix.relations.length > 0) not.push({ field: 'relation_type', op: 'in', value: [...choix.relations] });
  if (choix.natures.length > 0) not.push({ field: 'entity_nature', op: 'in', value: [...choix.natures] });
  if (choix.typesMedia.length > 0) {
    not.push({ field: 'tags', op: 'contains_any', value: choix.typesMedia.map(slugTypeMedia) });
  }
  return not;
}
