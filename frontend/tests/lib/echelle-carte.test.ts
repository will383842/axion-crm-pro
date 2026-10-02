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

/** Totaux par département relevés en production le 2026-10-02 (lecture seule). */
const TOTAUX_PROD = [
  9, 17, 42, 3496, 3509, 5351, 5734, 5844, 6042, 7146, 7203, 7316, 7540, 7642, 8152, 9450, 9576, 9590, 9701, 9960,
  10052, 10744, 10940, 10976, 11681, 12138, 12307, 12581, 12788, 13100, 13121, 13408, 13488, 13932, 14012, 14740,
  14806, 14870, 15854, 16041, 16221, 16452, 16472, 16645, 17354, 18112, 18904, 19270, 19569, 20473, 20505, 21967,
  23823, 24229, 25115, 25721, 26296, 26589, 27000, 27529, 27960, 29209, 29848, 30516, 30845, 32051, 33218, 33864,
  33980, 34406, 35747, 36359, 36386, 36734, 40402, 40520, 41200, 46110, 46844, 48622, 48857, 49683, 50422, 55253,
  55529, 57018, 64799, 73553, 73672, 74377, 80443, 82698, 83990, 86583, 92545, 94645, 95677, 106360, 117042,
  119010, 142585, 153306, 160892, 173857, 615507,
];

/**
 * La couleur que la carte donnera à un total : on ÉVALUE l'expression `step`
 * réellement produite (sémantique MapLibre : couleur par défaut, puis la
 * couleur du dernier palier ≤ valeur), au lieu de réimplémenter l'échelle.
 */
function evaluerStep(expr: unknown[], valeur: number): string {
  expect(expr[0]).toBe('step');
  let couleur = expr[2] as string;
  for (let i = 3; i < expr.length; i += 2) {
    if (valeur >= (expr[i] as number)) couleur = expr[i + 1] as string;
  }
  return couleur;
}

function couleurDe(echelle: ReturnType<typeof echelleRelative>, total: number): string {
  return evaluerStep(expressionCouleur(echelle, ['get', 'total']), total);
}

/** La case de légende dont l'intervalle contient la valeur (une et une seule). */
function caseDe(echelle: ReturnType<typeof echelleRelative>, valeur: number) {
  const cases = echelle.legende.filter((c) => valeur >= c.min && (c.max === null || valeur < c.max));
  expect(cases).toHaveLength(1);
  return cases[0]!;
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

  it('totaux mesurés en production le 02/10 (105 zones, de 9 à 615 507) : cinq teintes', () => {
    const echelle = echelleRelative(TOTAUX_PROD);
    const utilisees = new Set(TOTAUX_PROD.map((t) => couleurDe(echelle, t)));

    expect(echelle.seuils).toEqual([1, 10000, 15000, 30000, 50000]);
    expect(utilisees.size).toBe(5);
    expect(echelle.legende.map((l) => lisible(l.libelle))).toEqual([
      'aucune',
      'moins de 10 000',
      '10 000 à 15 000',
      '15 000 à 30 000',
      '30 000 à 50 000',
      '50 000 et plus',
    ]);
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
    expect(echelle.legende[0]).toEqual({ couleur: COULEUR_VIDE, libelle: 'aucune', min: 0, max: 1 });
    expect(lisible(echelle.legende[1]!.libelle)).toMatch(/^moins de /);
    echelle.seuils.slice(1).forEach((s, i) => {
      expect(echelle.legende[i + 2]!.couleur).toBe(echelle.couleurs[i + 1]);
      expect(lisible(echelle.legende[i + 2]!.libelle)).toContain(lisible(nombreLisible(s)));
    });
    expect(lisible(echelle.legende.at(-1)!.libelle)).toMatch(/ et plus$/);
  });

  it('totaux tous égaux : une seule classe, sans planter', () => {
    const echelle = echelleRelative([5000, 5000, 5000, 5000]);

    expect(echelle.seuils).toEqual([1]);
    expect(echelle.couleurs).toHaveLength(1);
    expect(echelle.legende.map((l) => lisible(l.libelle))).toEqual(['aucune', '1 et plus']);
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
  it('arrondit vers le bas à un nombre rond', () => {
    expect(arrondiLisible(3412)).toBe(3000);
    expect(arrondiLisible(16472)).toBe(15000);
    expect(arrondiLisible(23870)).toBe(20000);
    expect(arrondiLisible(615507)).toBe(600000);
    expect(arrondiLisible(0)).toBe(1);
  });

  it('écrit les nombres à la française', () => {
    expect(lisible(nombreLisible(100000))).toBe('100 000');
  });
});

describe('expressionCouleur', () => {
  it('de bout en bout : la couleur calculée par l expression = la couleur de la case de légende qui contient la valeur', () => {
    for (const totaux of [TOTAUX_PROD, totauxRealistes(), [5000, 5000], []]) {
      const echelle = echelleRelative(totaux);
      const expr = expressionCouleur(echelle, ['get', 'total']);
      for (const v of [0, 1, 9, 5351, 9999, 10000, 12000, 14999, 15000, 29999, 30000, 49999, 50000, 615507]) {
        expect(evaluerStep(expr, v)).toBe(caseDe(echelle, v).couleur);
      }
    }
    // Lecture humaine, sur les chiffres de la production.
    const prod = echelleRelative(TOTAUX_PROD);
    expect(lisible(caseDe(prod, 5351).libelle)).toBe('moins de 10 000');
    expect(lisible(caseDe(prod, 12000).libelle)).toBe('10 000 à 15 000');
    expect(lisible(caseDe(prod, 615507).libelle)).toBe('50 000 et plus');
    expect(caseDe(prod, 0).libelle).toBe('aucune');
  });

  it('gris sous 1, puis une couleur par seuil', () => {
    const echelle = echelleRelative(totauxRealistes());
    const expr = expressionCouleur(echelle, ['get', 'total']);

    expect(expr.slice(0, 3)).toEqual(['step', ['get', 'total'], COULEUR_VIDE]);
    expect(expr).toHaveLength(3 + 2 * echelle.seuils.length);
    expect(expr[3]).toBe(1);
  });
});
