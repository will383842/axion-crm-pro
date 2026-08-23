/**
 * D27-007 — LES OMBRES PASSENT PAR LEURS JETONS, ET PAR RIEN D'AUTRE.
 *
 * Le défaut n'était pas esthétique, il était de DUPLICATION : `--shadow-card` et
 * `--shadow-card-hover` sont définis une fois dans `src/styles/index.css`, et
 * `/coverage` en portait des copies littérales — qui avaient déjà divergé.
 *
 * Mesure du 2026-08-22, avant correctif (6 occurrences) :
 *   - CoveragePage.tsx:280, :313, :400 → `0_4px_24px_-8px_rgb(0_0_0/0.06)`,
 *     soit la PREMIÈRE couche de `--shadow-card` ; la seconde
 *     (`0 1px 2px 0 rgb(0 0 0 / 0.04)`) avait disparu ;
 *   - CoveragePage.tsx:280 (survol) → `0_8px_32px_-8px_rgb(0_0_0/0.10)`, même
 *     amputation sur `--shadow-card-hover` ;
 *   - CoveragePage.tsx:166 → `0_8px_32px_-12px_rgb(0_0_0/0.12)` et
 *     FranceCoverageMap.tsx:323 → `0_8px_32px_-8px_rgb(0_0_0/0.12)` : deux
 *     valeurs qui ne correspondaient à AUCUN des trois jetons.
 *
 * Ces gardes lisent les SOURCES comme un texte, sans monter aucun composant :
 * jsdom n'applique pas Tailwind et ne résout aucune variable de thème, donc un
 * test qui monterait un composant pour « vérifier l'ombre » certifierait
 * quelque chose qu'il n'inspecte pas.
 *
 * ⚠️ CE QU'ELLES NE PROUVENT PAS : aucun pixel n'est mesuré, et une ombre posée
 * en CSS (hors classe utilitaire) leur échappe. Elles prouvent exactement deux
 * choses : (1) aucune ombre utilitaire n'est écrite en valeur brute sous `src/`,
 * (2) tout jeton d'ombre référencé sous `src/` est bien DÉFINI dans la feuille.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, readdirSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const racine = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(racine, '../../src');
const FEUILLE = path.resolve(racine, '../../src/styles/index.css');

/**
 * Parcours récursif à la main (`readdirSync` + récursion), volontairement
 * simple : on veut pouvoir compter les fichiers visités et l'ASSURER plus bas.
 * Une garde qui parcourt moins que ce qu'elle croit est une garde qui certifie
 * ce qu'elle n'a pas regardé — c'est déjà arrivé dans ce dépôt.
 */
function sourcesDe(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier, { withFileTypes: true })) {
    const chemin = path.join(dossier, entree.name);
    if (entree.isDirectory()) sourcesDe(chemin, acc);
    else if (/\.(ts|tsx)$/.test(entree.name)) acc.push(chemin);
  }
  return acc;
}

const FICHIERS = sourcesDe(SRC);
const relatif = (f: string) => path.relative(SRC, f).replace(/\\/g, '/');

describe('D27-007 — les ombres viennent des jetons', () => {
  it('parcourt réellement l’arborescence `src/` (sinon la garde ne prouve rien)', () => {
    // Témoin de parcours : le jour où un `readdirSync` tronque ou où quelqu'un
    // déplace `src/`, cette garde rougit AVANT de rendre un vert vide.
    expect(
      FICHIERS.length,
      `D27-007 : seulement ${FICHIERS.length} fichier(s) .ts/.tsx trouvé(s) sous ` +
        '`frontend/src`. Le parcours est tronqué ou la racine a bougé — les deux ' +
        'gardes ci-dessous seraient alors vertes sans avoir rien inspecté. ' +
        'GESTE : vérifier le chemin `SRC` en tête de ce fichier.',
    ).toBeGreaterThan(80);
    expect(FICHIERS.map(relatif)).toContain('features/coverage/CoveragePage.tsx');
  });

  it('aucune ombre utilitaire n’est écrite en valeur brute sous `src/`', () => {
    // Tout `shadow-[…]` dont le contenu n'est pas `var(--…)` est une copie
    // littérale : c'est exactement la forme qui a divergé sur /coverage.
    const litterales = /shadow-\[(?!var\()/g;

    const fautifs: string[] = [];
    for (const fichier of FICHIERS) {
      const lignes = readFileSync(fichier, 'utf8').split('\n');
      lignes.forEach((ligne, i) => {
        litterales.lastIndex = 0;
        if (litterales.test(ligne)) fautifs.push(`${relatif(fichier)}:${i + 1}`);
      });
    }

    expect(
      fautifs,
      'D27-007 : ombre(s) écrite(s) en valeur brute au lieu du jeton — ' +
        `${fautifs.join(', ')}. Une copie littérale diverge : les six copies de ` +
        '`/coverage` avaient perdu la couche courte du jeton (mesure du 2026-08-22). ' +
        'GESTE : remplacer par `shadow-[var(--shadow-card)]`, ' +
        '`hover:shadow-[var(--shadow-card-hover)]` ou `shadow-[var(--shadow-popover)]` ' +
        'selon l’intention ; si aucune ne convient, AJOUTER un jeton dans ' +
        '`src/styles/index.css` plutôt qu’une valeur de plus.',
    ).toEqual([]);
  });

  it('tout jeton d’ombre référencé sous `src/` est défini dans `index.css`', () => {
    // Sans cette seconde garde, la première serait contournable en écrivant
    // `shadow-[var(--shadow-inventé)]` : la forme serait bonne, l'ombre absente.
    const feuille = readFileSync(FEUILLE, 'utf8');
    const definis = new Set(
      [...feuille.matchAll(/^\s*(--shadow-[\w-]+)\s*:/gm)].map((m) => m[1] as string),
    );

    const manquants = new Set<string>();
    for (const fichier of FICHIERS) {
      const texte = readFileSync(fichier, 'utf8');
      for (const m of texte.matchAll(/var\((--shadow-[\w-]+)\)/g)) {
        const jeton = m[1] as string;
        if (!definis.has(jeton)) manquants.add(`${jeton} (${relatif(fichier)})`);
      }
    }

    expect(
      [...manquants],
      `D27-007 : jeton(s) d’ombre référencé(s) mais jamais défini(s) — ${[...manquants].join(', ')}. ` +
        'La classe se compile sans erreur et l’élément se rend SANS OMBRE : le défaut ' +
        'est invisible en revue. GESTE : définir le jeton dans le bloc « Shadows » de ' +
        '`src/styles/index.css`, ou pointer un jeton existant ' +
        `(${[...definis].join(', ')}).`,
    ).toEqual([]);
  });
});
