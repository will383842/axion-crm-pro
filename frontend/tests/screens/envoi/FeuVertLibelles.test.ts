import { describe, expect, it } from 'vitest';
import { avertissementDemo, libelleBloquants } from '@/features/envoi/pilotage/feuVertLibelles';

describe('feu vert : récapitulatif', () => {
  it('affiche « non évalué » quand des points restent sans être évalués (jamais « 0 point bloquant »)', () => {
    expect(libelleBloquants({ faits: 3, total: 5, bloquants_restants: 0 })).toBe('Non évalué : 2 points ne sont pas encore évalués');
    expect(libelleBloquants({ faits: 4, total: 5, bloquants_restants: 0 })).toBe('Non évalué : 1 point n’est pas encore évalué');
  });
  it('compte les points bloquants au singulier et au pluriel', () => {
    expect(libelleBloquants({ faits: 3, total: 5, bloquants_restants: 1 })).toBe('1 point bloquant à lever');
    expect(libelleBloquants({ faits: 2, total: 5, bloquants_restants: 3 })).toBe('3 points bloquants à lever');
  });
  it('tout est rempli', () => {
    expect(libelleBloquants({ faits: 5, total: 5, bloquants_restants: 0 })).toBe('Tous les critères sont remplis.');
  });
  it('prévient en démonstration que « fait » est sans effet réel', () => {
    expect(avertissementDemo(true)).toMatch(/aucun effet réel/);
    expect(avertissementDemo(false)).toBeNull();
  });
});
