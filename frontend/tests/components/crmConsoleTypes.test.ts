/**
 * Le lexique de la conception §4.3 : « zéro jargon à l'écran ».
 *
 * Ces tests gardent la règle qui se perd toujours en premier — un slug
 * technique (`relation_type`, `sect:btp`, `presse_media`) qui atteint
 * l'utilisateur parce que quelqu'un a affiché la valeur brute « juste pour
 * cette colonne ».
 */
import { describe, it, expect } from 'vitest';
import {
  CANDIDATE_FAMILIES,
  CANDIDATE_FAMILY_LABELS,
  CANDIDATE_STAGE_LABELS,
  LIFECYCLE_LABELS,
  RELATION_TYPES,
  RELATION_TYPE_LABELS,
  tagLabel,
} from '@/features/crm-console/types';

describe('lexique de la console CRM v2', () => {
  it('donne un libellé humain à CHAQUE type de relation', () => {
    for (const type of RELATION_TYPES) {
      const label = RELATION_TYPE_LABELS[type];
      expect(label.length).toBeGreaterThan(0);
      // Un libellé qui contient encore un underscore est un slug déguisé.
      expect(label).not.toContain('_');
    }
  });

  it('donne un libellé humain à CHAQUE famille de métiers du vivier', () => {
    for (const family of CANDIDATE_FAMILIES) {
      expect(CANDIDATE_FAMILY_LABELS[family]).not.toContain('candidat_');
    }
  });

  it('couvre toutes les étapes des deux univers', () => {
    expect(Object.keys(LIFECYCLE_LABELS)).toHaveLength(6);
    expect(Object.keys(CANDIDATE_STAGE_LABELS)).toHaveLength(6);
  });

  it('traduit un tag gouverné en « Namespace · valeur »', () => {
    expect(tagLabel('sect:btp')).toBe('Secteur · btp');
    expect(tagLabel('src:calendly')).toBe('Source · calendly');
    expect(tagLabel('cand-dispo:immediate')).toBe('Dispo · immediate');
  });

  it('ne casse pas sur un slug sans namespace', () => {
    // La gouvernance impose `namespace:valeur`, mais le stock historique porte
    // des tags nus : l'écran doit les afficher, pas planter.
    expect(tagLabel('legacy')).toBe('legacy');
  });
});
