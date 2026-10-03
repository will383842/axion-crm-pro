/**
 * Statut d'un passage de collecte en clair — `CampaignDetailPage.tsx`
 * (audit UX lot 14, 2026-10-03) : l'onglet des passages affichait `running`,
 * `failed`… tels quels.
 */
import { describe, expect, it } from 'vitest';

import { libelleStatutPassage } from '@/features/campaigns/CampaignDetailPage';

describe('libelleStatutPassage', () => {
  it.each([
    ['running', 'En cours'],
    ['completed', 'Terminée'],
    ['success', 'Terminée'],
    ['failed', 'Échouée'],
    ['paused', 'En pause'],
    ['cancelled', 'Annulée'],
    ['pending', 'En attente'],
  ])('%s → %s', (statut, libelle) => {
    expect(libelleStatutPassage(statut)).toBe(libelle);
  });

  it('un statut inconnu (ou « constructor ») devient « Autre », jamais le code brut', () => {
    expect(libelleStatutPassage('zz_inconnu')).toBe('Autre');
    expect(libelleStatutPassage('constructor')).toBe('Autre');
    expect(libelleStatutPassage(null)).toBe('Autre');
  });
});
