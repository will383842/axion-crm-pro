/**
 * LOT 2 UX — LISIBILITÉ : plus aucun texte à 10 ou 11 px.
 *
 * Audit du 02/10/2026, P1-11 : 138 textes en `text-[10px]` / `text-[11px]`,
 * concentrés dans les composants communs (titres de section du menu, cartes de
 * chiffres, onglets, en-têtes de tableau, pastilles). Le plancher est
 * désormais `text-xs` (12 px) ; les titres de section du menu passent à 13 px.
 *
 * Seule exception : les initiales de l'avatar `xs` (cercle de 24 px), où
 * 10 px est la taille du dessin et non d'un texte à lire.
 */
import { describe, it, expect } from 'vitest';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const racine = path.dirname(fileURLToPath(import.meta.url));
const SRC = path.resolve(racine, '../../src');

const EXCEPTIONS = new Set(['components/ui/Avatar.tsx']);

function fichiers(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = path.join(dossier, entree);
    if (statSync(chemin).isDirectory()) fichiers(chemin, acc);
    else if (/\.tsx?$/.test(entree)) acc.push(chemin);
  }
  return acc;
}

describe('Lisibilité — plancher de 12 px', () => {
  it('trouve bien des fichiers à lire (témoin)', () => {
    expect(fichiers(SRC).length).toBeGreaterThan(100);
  });

  it('aucun `text-[10px]`, `text-[11px]` ni plus petit hors exception', () => {
    const fautifs: string[] = [];
    for (const chemin of fichiers(SRC)) {
      const rel = path.relative(SRC, chemin).split(path.sep).join('/');
      if (EXCEPTIONS.has(rel)) continue;
      readFileSync(chemin, 'utf8')
        .split('\n')
        .forEach((ligne, i) => {
          if (/text-\[(?:[0-9]|1[01])px\]/.test(ligne)) fautifs.push(`${rel}:${i + 1}`);
        });
    }
    expect(fautifs, 'Texte trop petit : utiliser `text-xs` (12 px) au minimum.').toEqual([]);
  });

  it('les titres de section du menu sont à 13 px', () => {
    const barre = readFileSync(path.join(SRC, 'components/layout/Sidebar.tsx'), 'utf8');
    expect(barre).toContain('text-[13px] font-semibold uppercase');
  });
});
