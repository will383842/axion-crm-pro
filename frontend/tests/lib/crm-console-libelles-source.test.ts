import { describe, expect, it } from 'vitest';

import { SOURCE_PERSONNE_LABELS } from '@/features/crm-console/types';

describe('CRM-G3 — libellés des origines de personnes', () => {
  it('affiche un libellé lisible pour « guide-reseau », pas le code brut', () => {
    expect(SOURCE_PERSONNE_LABELS['guide-reseau']).toBe('Guide reçu via les réseaux sociaux');
  });

  it('garde les libellés voisins inchangés', () => {
    expect(SOURCE_PERSONNE_LABELS.newsletter).toBe('Newsletter');
    expect(SOURCE_PERSONNE_LABELS['guide-ia']).toBe('Guide IA entreprise');
  });
});
