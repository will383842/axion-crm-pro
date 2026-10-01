/**
 * PRESSE ET EXCLUSIONS comme critères d'audience (harmonisation des contacts,
 * 2026-09-30) — `src/features/audiences/presse.ts`.
 *
 * Le serveur vise le type et la zone d'un média par leurs étiquettes
 * `media-type:` / `media-zone:`. Les exclusions de nature et de type partent
 * dans `not`, à côté de celles de la relation (chantier B). Jamais une
 * condition vide.
 */
import { describe, expect, it } from 'vitest';

import {
  CRITERE_AUDIENCE_PRESSE,
  TYPE_MEDIA_PRESETS,
  ZONE_MEDIA_PRESETS,
  avecExclusions,
  criteresAudiencePresse,
  criteresMedias,
  exclusions,
  slugTypeMedia,
  slugZoneMedia,
} from '@/features/audiences/presse';
import { TYPES_MEDIA, ZONES_MEDIA } from '@/lib/referentiels.generated';

describe('critères de presse', () => {
  it('rien de choisi : aucune condition, ni dans `all`, ni dans `not`', () => {
    expect(criteresMedias([], [])).toEqual([]);
    expect(exclusions({ natures: [], typesMedia: [] })).toEqual([]);
    expect(avecExclusions({ all: [] }, [])).toEqual({ all: [] });
  });

  it('type ET zone : deux conditions distinctes (un ET), sur les étiquettes du serveur', () => {
    expect(criteresMedias(['radio', 'tv'], ['regional'])).toEqual([
      { field: 'tags', op: 'contains_any', value: ['media-type:radio', 'media-type:tv'] },
      { field: 'tags', op: 'contains_any', value: ['media-zone:regional'] },
    ]);
  });

  it('les exclusions s’AJOUTENT au bloc `not` existant (celui de la relation), sans le remplacer', () => {
    const relation = { field: 'relation_type', op: 'in' as const, value: ['client'] };
    expect(avecExclusions({ all: [], not: [relation] }, exclusions({ natures: ['media'], typesMedia: ['production'] }))).toEqual({
      all: [],
      not: [
        relation,
        { field: 'entity_nature', op: 'in', value: ['media'] },
        { field: 'tags', op: 'contains_any', value: ['media-type:production'] },
      ],
    });
  });

  it('les listes sont celles du référentiel généré, et chaque slug est unique', () => {
    expect(TYPE_MEDIA_PRESETS.map((p) => p.code)).toEqual(TYPES_MEDIA.map((t) => t.code));
    expect(ZONE_MEDIA_PRESETS.map((p) => p.code)).toEqual(ZONES_MEDIA.map((z) => z.code));
    const slugs = [...TYPES_MEDIA.map((t) => slugTypeMedia(t.code)), ...ZONES_MEDIA.map((z) => slugZoneMedia(z.code))];
    expect(new Set(slugs).size).toBe(slugs.length);
    for (const s of slugs) expect(s).toMatch(/^media-(type|zone):[a-z0-9]+(-[a-z0-9]+)*$/);
  });

  it('audience presse : le critère presse en tête, la géographie et les critères médias — sans critère de prospection', () => {
    const medias = criteresMedias([], [], { themes: ['economie-entreprise'] });
    expect(criteresAudiencePresse({ departements: ['69'], regions: [], etiquettes: [] }, medias, ['production'])).toEqual({
      all: [
        CRITERE_AUDIENCE_PRESSE,
        { field: 'department_code', op: 'in', value: ['69'] },
        ...medias,
      ],
      not: [{ field: 'tags', op: 'contains_any', value: ['media-type:production'] }],
    });
    expect(CRITERE_AUDIENCE_PRESSE).toEqual({ field: 'segment', op: 'eq', value: 'presse' });
    expect(criteresAudiencePresse({ departements: [], regions: [], etiquettes: [] }, [], [])).toEqual({ all: [CRITERE_AUDIENCE_PRESSE] });
  });
});
