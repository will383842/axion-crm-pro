/**
 * Échelle de couleurs de la carte des départements, RELATIVE aux volumes
 * affichés.
 *
 * 🔴 Constat en production (2026-10-02, après #286) : la carte était colorée,
 * mais tous les départements avaient la même teinte foncée. L'échelle était
 * fixe (0 / 10 / 100 / 500 / 2 000) alors que chaque département compte de
 * quelques milliers à 615 507 entreprises : tout dépassait 2 000.
 *
 * Désormais les seuils sont calculés à partir des totaux reçus (quantiles,
 * arrondis vers le bas à un nombre lisible), et la légende est générée depuis
 * ces mêmes seuils. 0 reste gris, distinct de toute classe.
 *
 * Module pur : ni maplibre-gl, ni React. Il se teste sans WebGL.
 */

/** Gris des départements sans aucune entreprise. */
export const COULEUR_VIDE = '#e2e8f0';

/** Du plus clair au plus foncé. */
export const PALETTE = ['#e0f2fe', '#7dd3fc', '#38bdf8', '#0284c7', '#075985'] as const;

export interface ClasseLegende {
  couleur: string;
  libelle: string;
  /** Intervalle couvert par la case : `min` inclus, `max` exclu (`null` = sans fin). */
  min: number;
  max: number | null;
}

export interface EchelleCarte {
  /** Borne basse de chaque classe colorée, croissante ; la première vaut 1. */
  seuils: number[];
  /** Une couleur par seuil. */
  couleurs: string[];
  /** La case grise « aucune » (0), puis une entrée par classe. */
  legende: ClasseLegende[];
}

/** Les « jolis » chiffres de tête : 1, 1,5, 2, 2,5, 3, 4… 9. */
const CHIFFRES_RONDS = [1, 1.5, 2, 2.5, 3, 4, 5, 6, 7, 8, 9] as const;

/**
 * Arrondi vers le bas à un nombre rond : 3 412 → 3 000, 16 472 → 15 000,
 * 23 870 → 20 000, 615 507 → 600 000. Lisible d'un coup d'œil.
 *
 * Un seul chiffre significatif ne suffit pas : mesuré sur la production,
 * 10 744 et 16 472 tombaient tous deux à 10 000 et la carte perdait une teinte.
 */
export function arrondiLisible(valeur: number): number {
  if (!Number.isFinite(valeur) || valeur < 1) return 1;
  const puissance = 10 ** Math.floor(Math.log10(valeur));
  const tete = valeur / puissance;
  let rond: number = CHIFFRES_RONDS[0];
  for (const c of CHIFFRES_RONDS) {
    if (c <= tete) rond = c;
  }
  return Math.max(1, Math.floor(rond * puissance));
}

/** « 5 000 », avec une espace insécable (jamais coupée en fin de ligne). */
export function nombreLisible(valeur: number): string {
  return valeur.toLocaleString('fr-FR').replace(/\s/g, '\u00a0');
}

function couleursPour(nbClasses: number): string[] {
  const dernier = PALETTE.length - 1;
  if (nbClasses <= 1) return [PALETTE[Math.floor(dernier / 2)]!];
  return Array.from({ length: nbClasses }, (_, i) => PALETTE[Math.round((i * dernier) / (nbClasses - 1))]!);
}

/**
 * Calcule l'échelle à partir des totaux affichés.
 *
 * - Les totaux nuls, négatifs ou non numériques ne comptent pas (ils sont gris).
 * - Les seuils sont les quantiles des totaux positifs, arrondis vers le bas ;
 *   un seuil qui ne dépasse pas le précédent ou le plus petit total (classe
 *   vide) est écarté. Des totaux tous égaux donnent donc UNE seule classe.
 * - Aucun total positif : une seule classe, rien ne plante.
 */
export function echelleRelative(totaux: readonly number[], nbClasses = PALETTE.length): EchelleCarte {
  const positifs = totaux
    .map((t) => Number(t))
    .filter((t) => Number.isFinite(t) && t > 0)
    .sort((a, b) => a - b);

  const seuils = [1];
  const classes = Math.max(1, Math.min(Math.floor(nbClasses), PALETTE.length));
  if (positifs.length > 0) {
    const minimum = positifs[0]!;
    for (let i = 1; i < classes; i += 1) {
      const brut = positifs[Math.floor((i * positifs.length) / classes)]!;
      const seuil = arrondiLisible(brut);
      if (seuil > seuils[seuils.length - 1]! && seuil > minimum) {
        seuils.push(seuil);
      }
    }
  }

  const couleurs = couleursPour(seuils.length);
  // Libell\u00e9s par INTERVALLE (relecture A09 de #291) : la premi\u00e8re case
  // couvre de 1 \u00e0 9 999 ; l'intituler par la plus petite valeur (\u00ab 9 \u00bb)
  // faisait lire \u00ab 9 entreprises \u00bb sur un d\u00e9partement \u00e0 5 351.
  const legende: ClasseLegende[] = [
    { couleur: COULEUR_VIDE, libelle: 'aucune', min: 0, max: 1 },
    ...seuils.map((seuil, i): ClasseLegende => {
      const suivant = seuils[i + 1] ?? null;
      let libelle: string;
      if (suivant === null) libelle = `${nombreLisible(seuil)} et plus`;
      else if (i === 0) libelle = `moins de ${nombreLisible(suivant)}`;
      else libelle = `${nombreLisible(seuil)} \u00e0 ${nombreLisible(suivant)}`;
      return { couleur: couleurs[i]!, libelle, min: seuil, max: suivant };
    }),
  ];

  return { seuils, couleurs, legende };
}

/**
 * L'expression MapLibre `step` : gris sous 1, puis la couleur de chaque
 * classe à partir de son seuil.
 */
export function expressionCouleur(echelle: EchelleCarte, entree: unknown): unknown[] {
  const expression: unknown[] = ['step', entree, COULEUR_VIDE];
  echelle.seuils.forEach((seuil, i) => {
    expression.push(seuil, echelle.couleurs[i]);
  });
  return expression;
}
