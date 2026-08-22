import { readdirSync, statSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import config from '../vitest.config';

/**
 * GARDE : LA COLLECTE VITEST DU PAQUET `workers` NE LAISSE AUCUN TEST DEHORS
 * — constat H44-010 (S3).
 *
 * CE QUI ETAIT LA. `vitest.config.ts` portait un `include` borne a `tests/`.
 * Un `*.test.ts` pose sous `workers/src/` n'aurait jamais ete collecte, et rien
 * ne l'aurait dit : `passWithNoTests: false` fait rougir une suite VIDE, pas une
 * suite PARTIELLE. Les tests de `tests/` passaient, ceux de `src/` etaient
 * ignores, et la commande rendait vert.
 *
 * POURQUOI UNE GARDE PLUTOT QU'UN COMMENTAIRE. Parce que l'elargissement du
 * glob se defait en une ligne, et que le defaut qu'il rouvrirait est
 * INVISIBLE : personne ne voit un test qui ne tourne pas. La garde compare donc
 * ce qui est SUR LE DISQUE a ce que la configuration REELLE collecte, et elle
 * lit cette configuration en l'important — pas en recopiant ses globs, ce qui
 * ne prouverait que la coherence de la garde avec elle-meme.
 *
 * ⚠️ Le parcours du disque est un `readdirSync` recursif ecrit a la main. Ce
 * depot a mesure qu'un iterateur recursif TRONQUE le parcours sur ce montage
 * Docker (14 fichiers vus sur 56 presents) : une garde de completude batie
 * dessus certifierait l'absence de ce qu'elle n'a pas parcouru.
 */

const racineDuPaquet = resolve(dirname(fileURLToPath(import.meta.url)), '..');

/** Repertoires que la collecte ne visite pas, et qu'il serait absurde de parcourir. */
const REPERTOIRES_IGNORES = new Set(['node_modules', 'dist', 'coverage', '.git']);

/** Chemins relatifs (separateur `/`) de tous les `*.test.ts` du paquet. */
function fichiersDeTestSurDisque(depuis = racineDuPaquet, prefixe = ''): string[] {
  const trouves: string[] = [];

  for (const entree of readdirSync(depuis)) {
    if (REPERTOIRES_IGNORES.has(entree)) continue;

    const chemin = resolve(depuis, entree);
    const relatif = prefixe === '' ? entree : `${prefixe}/${entree}`;

    if (statSync(chemin).isDirectory()) {
      trouves.push(...fichiersDeTestSurDisque(chemin, relatif));
    } else if (entree.endsWith('.test.ts')) {
      trouves.push(relatif);
    }
  }

  return trouves;
}

/**
 * Un glob de collecte, traduit en expression reguliere.
 *
 * Volontairement minimal : il ne couvre que `**` et `*`, les deux seules formes
 * employees par la configuration. Un glob plus riche pose la-bas ferait mentir
 * cette traduction — c'est pourquoi le temoin negatif plus bas verifie que le
 * traducteur DISCRIMINE, au lieu de le supposer.
 */
function globVersRegex(glob: string): RegExp {
  // Deux marques intermediaires, pour que la traduction de `**` ne soit pas
  // re-traduite par celle de `*`. Elles portent des espaces : aucun glob de
  // collecte n'en contient, donc aucune collision possible.
  const SEGMENTS = '<< segments >>'; // `**/` : zero ou plusieurs segments
  const TOUT = '<< tout >>'; // `**` seul : n'importe quoi

  const motif = glob
    .replace(/[.+^${}()|[\]\\]/g, '\\$&')
    .replace(/\*\*\//g, SEGMENTS)
    .replace(/\*\*/g, TOUT)
    .replace(/\*/g, '[^/]*')
    .split(SEGMENTS)
    .join('(?:.*/)?')
    .split(TOUT)
    .join('.*');

  return new RegExp(`^${motif}$`);
}

// `as unknown as` volontaire : `defineConfig` rend une union (objet OU fonction)
// selon la version de Vite, et un cast direct serait refuse par TypeScript sur
// certaines d'entre elles. On ne lit ici que les deux champs qui nous occupent.
const globsDeCollecte = (config as unknown as { test?: { include?: string[]; exclude?: string[] } })
  .test;

describe('H44-010 — la collecte Vitest des workers ne laisse aucun test dehors', () => {
  it('TEMOIN NEGATIF : le traducteur de globs sait distinguer les deux formes', () => {
    // Sans ce temoin, un traducteur qui accepterait tout ferait passer le test
    // final au vert sans rien mesurer — le meme vert menteur que le constat.
    const large = globVersRegex('**/*.test.ts');
    const etroit = globVersRegex('tests/**/*.test.ts'); // la forme d'AVANT H44-010

    expect(large.test('src/futur.test.ts')).toBe(true);
    expect(large.test('tests/extract.test.ts')).toBe(true);

    // C'EST TOUT LE CONSTAT, en une ligne : la forme d'avant ne voyait pas `src/`.
    expect(etroit.test('src/futur.test.ts')).toBe(false);
    expect(etroit.test('tests/extract.test.ts')).toBe(true);

    expect(globVersRegex('node_modules/**').test('node_modules/x/y.test.ts')).toBe(true);
    expect(globVersRegex('node_modules/**').test('src/y.test.ts')).toBe(false);
  });

  it('TEMOIN DE COUVERTURE : le parcours du disque voit bien les suites existantes', () => {
    const surDisque = fichiersDeTestSurDisque();

    // Un parcours qui ne rend rien rendrait la garde suivante vraie a vide.
    // Le paquet portait huit suites au 2026-08-22 ; on n'en fige pas le compte
    // (il doit pouvoir grandir), on refuse le zero et l'unite.
    expect(surDisque.length).toBeGreaterThan(1);
    expect(surDisque).toContain('tests/extract.test.ts');
  });

  it('tout `*.test.ts` du paquet est collecte par la configuration livree', () => {
    const include = globsDeCollecte?.include ?? [];
    const exclude = globsDeCollecte?.exclude ?? [];

    expect(include.length).toBeGreaterThan(0);

    const collecte = (relatif: string): boolean =>
      include.some((g) => globVersRegex(g).test(relatif)) &&
      !exclude.some((g) => globVersRegex(g).test(relatif));

    const oublies = fichiersDeTestSurDisque().filter((f) => !collecte(f));

    expect(
      oublies,
      `H44-010 — ces fichiers de test ne sont collectes par AUCUN glob de ` +
        `workers/vitest.config.ts :\n\n  ${oublies.join('\n  ')}\n\n` +
        `Ils ne tournent pas, et la suite reste VERTE : \`passWithNoTests: false\` fait ` +
        `rougir une suite vide, pas une suite partielle.\n\n` +
        `GESTE : elargir \`include\` pour les couvrir, ou — si ces fichiers ne sont PAS des ` +
        `tests — les renommer. Ne PAS les ajouter a \`exclude\` pour faire taire cette garde : ` +
        `ce serait ecrire noir sur blanc qu'on choisit de ne pas les jouer.\n` +
        `Globs actuels — include: ${JSON.stringify(include)}, exclude: ${JSON.stringify(exclude)}`,
    ).toEqual([]);
  });
});
