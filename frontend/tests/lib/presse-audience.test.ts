/**
 * RELATION, PRESSE ET EXCLUSIONS comme critères d'audience (harmonisation des
 * contacts, 2026-09-30) — `src/features/audiences/presse.ts`.
 *
 * Le serveur vise la relation par la colonne `relation_type`, le type et la
 * zone d'un média par leurs étiquettes `media-type:` / `media-zone:`. Les
 * exclusions partent dans `not`. Jamais une condition vide.
 */
import { describe, expect, it } from 'vitest';

import {
  EXCLUSIONS_PROSPECTION,
  RELATION_PRESETS,
  TYPE_MEDIA_PRESETS,
  ZONE_MEDIA_PRESETS,
  critereRelations,
  criteresMedias,
  exclusions,
  slugTypeMedia,
  slugZoneMedia,
} from '@/features/audiences/presse';
import { RELATIONS, TYPES_MEDIA, ZONES_MEDIA } from '@/lib/referentiels.generated';

describe('critères de relation et de presse', () => {
  it('rien de choisi : aucune condition, ni dans `all`, ni dans `not`', () => {
    expect(critereRelations([])).toBeNull();
    expect(criteresMedias([], [])).toEqual([]);
    expect(exclusions({ relations: [], natures: [], typesMedia: [] })).toEqual([]);
  });

  it('une relation visée : relation_type / in', () => {
    expect(critereRelations(['partenaire'])).toEqual({ field: 'relation_type', op: 'in', value: ['partenaire'] });
  });

  it('type ET zone : deux conditions distinctes (un ET), sur les étiquettes du serveur', () => {
    expect(criteresMedias(['radio', 'tv'], ['regional'])).toEqual([
      { field: 'tags', op: 'contains_any', value: ['media-type:radio', 'media-type:tv'] },
      { field: 'tags', op: 'contains_any', value: ['media-zone:regional'] },
    ]);
  });

  it('le préréglage « prospection » exclut la presse et les clients', () => {
    expect(EXCLUSIONS_PROSPECTION).toEqual(['presse_media', 'client']);
    expect(exclusions({ relations: EXCLUSIONS_PROSPECTION, natures: ['media'], typesMedia: ['production'] })).toEqual([
      { field: 'relation_type', op: 'in', value: ['presse_media', 'client'] },
      { field: 'entity_nature', op: 'in', value: ['media'] },
      { field: 'tags', op: 'contains_any', value: ['media-type:production'] },
    ]);
  });

  it('les listes sont celles du référentiel généré, et chaque slug est unique', () => {
    expect(RELATION_PRESETS.map((p) => p.code)).toEqual(RELATIONS.map((r) => r.code));
    expect(TYPE_MEDIA_PRESETS.map((p) => p.code)).toEqual(TYPES_MEDIA.map((t) => t.code));
    expect(ZONE_MEDIA_PRESETS.map((p) => p.code)).toEqual(ZONES_MEDIA.map((z) => z.code));
    const slugs = [...TYPES_MEDIA.map((t) => slugTypeMedia(t.code)), ...ZONES_MEDIA.map((z) => slugZoneMedia(z.code))];
    expect(new Set(slugs).size).toBe(slugs.length);
    for (const s of slugs) expect(s).toMatch(/^media-(type|zone):[a-z0-9]+(-[a-z0-9]+)*$/);
  });
});
