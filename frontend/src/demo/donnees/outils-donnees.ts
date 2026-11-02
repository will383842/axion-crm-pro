/**
 * Petits outils partagés des générateurs du jeu factice.
 */
import type { Alea } from '../graine';

/** Texte sans accents ni ponctuation, en minuscules, mots joints par `sep`. */
export function enAscii(texte: string, sep = '-'): string {
  return texte
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[’']/g, '')
    .replace(/[^a-z0-9]+/g, sep)
    .replace(new RegExp(`^\\${sep}+|\\${sep}+$`, 'g'), '');
}

/** Chiffre qui rendrait le numéro valide au contrôle de Luhn. */
function cleLuhn(debut: string): number {
  for (let c = 0; c <= 9; c += 1) {
    const numero = `${debut}${c}`;
    let somme = 0;
    numero
      .split('')
      .reverse()
      .forEach((ch, i) => {
        let n = Number(ch);
        if (i % 2 === 1) {
          n *= 2;
          if (n > 9) n -= 9;
        }
        somme += n;
      });
    if (somme % 10 === 0) return c;
  }
  return 0;
}

/**
 * SIREN de démo : 9 chiffres dont la clé de Luhn est volontairement FAUSSE.
 * Un tel numéro ne peut désigner aucune entreprise immatriculée.
 */
export function sirenInvalide(alea: Alea): string {
  let debut = String(alea.entier(1, 9));
  for (let i = 0; i < 7; i += 1) debut += String(alea.entier(0, 9));
  const juste = cleLuhn(debut);
  return `${debut}${(juste + alea.entier(1, 9)) % 10}`;
}

/** Téléphone dans la tranche réservée à la fiction (04 65 71 xx xx). */
export function telephoneFictif(alea: Alea): string {
  const deux = () => String(alea.entier(0, 99)).padStart(2, '0');
  return `04 65 71 ${deux()} ${deux()}`;
}

/** Arrondi à une décimale. */
export function arrondi1(n: number): number {
  return Math.round(n * 10) / 10;
}
