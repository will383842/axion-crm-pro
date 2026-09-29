/**
 * Le MÉTIER comme critère d'audience (chantier 2, 2026-09-29) —
 * `src/features/audiences/metiers.ts`.
 *
 * Le serveur vise un métier par son étiquette `metier-<code>` : la condition
 * doit porter EXACTEMENT ces slugs, tirés de la liste générée — jamais une
 * condition vide (elle ne viserait personne).
 */
import { describe, expect, it } from 'vitest';

import { METIER_PRESETS, critereMetiers, slugMetier } from '@/features/audiences/metiers';
import { METIERS, PREFIXE_ETIQUETTE_METIER } from '@/lib/referentiels.generated';

describe('critère « métier »', () => {
  it('rien de choisi : aucune condition', () => {
    expect(critereMetiers([])).toBeNull();
  });

  it('des métiers choisis : une condition tags / contains_any sur leurs slugs', () => {
    expect(critereMetiers(['coiffeurs', 'experts-comptables'])).toEqual({
      field: 'tags',
      op: 'contains_any',
      value: ['metier-coiffeurs', 'metier-experts-comptables'],
    });
  });

  it('chaque métier du référentiel a un slug unique, au préfixe du serveur', () => {
    const slugs = METIERS.map((m) => slugMetier(m.code));
    expect(PREFIXE_ETIQUETTE_METIER).toBe('metier-');
    expect(new Set(slugs).size).toBe(METIERS.length);
    for (const s of slugs) expect(s).toMatch(/^metier-[a-z0-9]+(-[a-z0-9]+)*$/);
    expect(METIER_PRESETS.map((p) => p.code)).toEqual(METIERS.map((m) => m.code));
  });
});
