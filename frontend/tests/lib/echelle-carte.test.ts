/**
 * GARDE — échelle de couleurs de la carte de France (constat prod du
 * 2026-10-02, après #286 : tous les départements de la même teinte foncée,
 * l'échelle s'arrêtant à 2 000 alors qu'un département compte de quelques
 * milliers à 615 507 entreprises).
 */
import { describe, expect, it } from 'vitest';

import {
  arrondiLisible,
  COULEUR_VIDE,
  echelleRelative,
  expressionCouleur,
  nombreLisible,
} from '@/features/coverage/echelleCarte';

/** 101 départements, de ~3 000 à 615 507, répartis comme en production. */
function totauxRealistes(): number[] {
  const n = 101;
  const min = 3000;
  const max = 615507;
  return Array.from({ length: n }, (_, i) => Math.round(min * (max / min) ** (i / (n - 1))));
}

/** La couleur que la carte donnera à un total, d'après l'échelle. */
function couleurDe(echelle: ReturnType<typeof echelleRelative>, total: number): string {
  if (total < 1) return COULEUR_VIDE;
  let couleur = COULEUR_VIDE;
  echelle.seuils.forEach((s, i) => {
    if (total >= s) couleur = echelle.couleurs[i]!;
  });
  return couleur;
}

const lisible = (s: string) => s.replace(/\u00a0/g, ' ');

describe('echelleRelative', () => {
  it('volumes réels (3 000 à 615 507) : au moins 4 teintes réellement utilisées', () => {
    const totaux = totauxRealistes();
    const echelle = echelleRelative(totaux);
    const utilisees = new Set(totaux.map((t) => couleurDe(echelle, t)));

    expect(utilisees.size).toBeGreaterThanOrEqual(4);
    expect(utilisees.has(COULEUR_VIDE)).toBe(false);
  });

  it('seuils croissants, arrondis lisibles, légende tirée des mêmes seuils', () => {
    const echelle = echelleRelative(totauxRealistes());

    expect(echelle.seuils[0]).toBe(1);
    for (let i = 1; i < echelle.seuils.length; i += 1) {
      expect(echelle.seuils[i]!).toBeGreaterThan(echelle.seuils[i - 1]!);
      expect(arrondiLisible(echelle.seuils[i]!)).toBe(echelle.seuils[i]);
    }
    expect(echelle.couleurs).toHaveLength(echelle.seuils.length);
    expect(echelle.legende).toHaveLength(echelle.seuils.length + 1);
    expect(echelle.legende[0]).toEqual({ couleur: COULEUR_VIDE, libelle: '0' });
    expect(lisible(echelle.legende[1]!.libelle)).toBe('3 000');
    echelle.seuils.slice(1).forEach((s, i) => {
      expect(echelle.legende[i + 2]!.couleur).toBe(echelle.couleurs[i + 1]);
      expect(lisible(echelle.legende[i + 2]!.libelle)).toContain(lisible(nombreLisible(s)));
    });
    expect(lisible(echelle.legende.at(-1)!.libelle)).toMatch(/ \+$/);
  });

  it('totaux tous égaux : une seule classe, sans planter', () => {
    const echelle = echelleRelative([5000, 5000, 5000, 5000]);

    expect(echelle.seuils).toEqual([1]);
    expect(echelle.couleurs).toHaveLength(1);
    expect(echelle.legende.map((l) => lisible(l.libelle))).toEqual(['0', '5 000 +']);
  });

  it('liste vide (ou que des zéros) : une seule classe, rien ne plante', () => {
    for (const totaux of [[], [0, 0], [Number.NaN, -3]]) {
      const echelle = echelleRelative(totaux);
      expect(echelle.seuils).toEqual([1]);
      expect(echelle.legende).toHaveLength(2);
    }
  });

  it('un département à 0 reste gris, distinct de toute classe', () => {
    const echelle = echelleRelative([0, ...totauxRealistes()]);
    expect(couleurDe(echelle, 0)).toBe(COULEUR_VIDE);
    expect(echelle.couleurs).not.toContain(COULEUR_VIDE);
  });
});

describe('arrondiLisible / nombreLisible', () => {
  it('arrondit vers le bas à un chiffre significatif', () => {
    expect(arrondiLisible(3412)).toBe(3000);
    expect(arrondiLisible(23870)).toBe(20000);
    expect(arrondiLisible(615507)).toBe(600000);
    expect(arrondiLisible(0)).toBe(1);
  });

  it('écrit les nombres à la française', () => {
    expect(lisible(nombreLisible(100000))).toBe('100 000');
  });
});

describe('expressionCouleur', () => {
  it('gris sous 1, puis une couleur par seuil', () => {
    const echelle = echelleRelative(totauxRealistes());
    const expr = expressionCouleur(echelle, ['get', 'total']);

    expect(expr.slice(0, 3)).toEqual(['step', ['get', 'total'], COULEUR_VIDE]);
    expect(expr).toHaveLength(3 + 2 * echelle.seuils.length);
    expect(expr[3]).toBe(1);
  });
});
