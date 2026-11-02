/**
 * Graine de la démo : un générateur pseudo-aléatoire MAISON, déterministe.
 *
 * Même graine, mêmes données, à l'octet (garde : `tests/lib/demo-donnees-factices.test.ts`).
 * Jamais `Math.random()` dans une donnée de démo : une démo qui change à chaque
 * chargement ne se présente pas deux fois de la même façon, et un test e2e ne
 * peut pas s'y fier.
 *
 * Chaque zone a SA suite de tirages (`graine('campagnes')`) : ajouter une zone
 * ne décale pas les données des autres.
 */

export const GRAINE = 20270112;

/** mulberry32 : 32 bits d'état, période 2^32, largement assez pour une démo. */
export function mulberry32(depart: number): () => number {
  let etat = depart >>> 0;
  return () => {
    etat = (etat + 0x6d2b79f5) >>> 0;
    let t = etat;
    t = Math.imul(t ^ (t >>> 15), t | 1);
    t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}

/** Empreinte FNV-1a d'un nom de zone (pour dériver une graine par zone). */
function empreinte(texte: string): number {
  let h = 0x811c9dc5;
  for (let i = 0; i < texte.length; i += 1) {
    h ^= texte.charCodeAt(i);
    h = Math.imul(h, 0x01000193) >>> 0;
  }
  return h >>> 0;
}

export interface Alea {
  /** Réel dans [0, 1[. */
  suivant(): number;
  /** Entier dans [min, max] (bornes comprises). */
  entier(min: number, max: number): number;
  /** Vrai avec la probabilité `p`. */
  booleen(p?: number): boolean;
  /** Un élément du tableau (non vide). */
  choisir<T>(liste: readonly T[]): T;
  /** `n` éléments distincts du tableau, dans un ordre tiré. */
  echantillon<T>(liste: readonly T[], n: number): T[];
}

export function creerAlea(depart: number): Alea {
  const tirer = mulberry32(depart);
  const alea: Alea = {
    suivant: tirer,
    entier: (min, max) => min + Math.floor(tirer() * (max - min + 1)),
    booleen: (p = 0.5) => tirer() < p,
    choisir: <T,>(liste: readonly T[]): T => {
      if (liste.length === 0) throw new Error('choisir() sur une liste vide');
      return liste[Math.floor(tirer() * liste.length)] as T;
    },
    echantillon: <T,>(liste: readonly T[], n: number): T[] => {
      const copie = [...liste];
      for (let i = copie.length - 1; i > 0; i -= 1) {
        const j = Math.floor(tirer() * (i + 1));
        [copie[i], copie[j]] = [copie[j] as T, copie[i] as T];
      }
      return copie.slice(0, Math.min(n, copie.length));
    },
  };
  return alea;
}

/** Le générateur d'une zone : `graine('campagnes')` rend toujours la même suite. */
export function graine(zone: string): Alea {
  return creerAlea((GRAINE ^ empreinte(zone)) >>> 0);
}
