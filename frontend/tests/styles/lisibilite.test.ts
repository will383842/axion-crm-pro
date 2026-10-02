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

/** 12 px = 0,75 rem (base 16 px). Un `em` est compté comme un `rem`. */
const PLANCHER_PX = 12;

function enPixels(valeur: number, unite: string): number {
  return unite === 'px' ? valeur : valeur * 16;
}

/** Vrai si la ligne fixe une taille de texte sous 12 px. */
function tailleTropPetite(ligne: string): boolean {
  for (const m of ligne.matchAll(/text-\[(\d*\.?\d+)(px|rem|em)\]/g)) {
    if (enPixels(Number(m[1]), m[2] as string) < PLANCHER_PX) return true;
  }
  for (const m of ligne.matchAll(/fontSize\s*:\s*['"`]?(\d*\.?\d+)(px|rem|em)?/g)) {
    if (enPixels(Number(m[1]), m[2] ?? 'px') < PLANCHER_PX) return true;
  }
  return false;
}

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

  it.each([
    ['text-[10px]', true],
    ['text-[11px]', true],
    ['text-[10.5px]', true],
    ['text-[11.9px]', true],
    ['text-[0.7rem]', true],
    ['text-[.65rem]', true],
    ['text-[0.6em]', true],
    ['style={{ fontSize: 10 }}', true],
    ["style={{ fontSize: '11px' }}", true],
    ["style={{ fontSize: '0.7rem' }}", true],
    ['text-xs', false],
    ['text-[12px]', false],
    ['text-[13px]', false],
    ['text-[0.75rem]', false],
    ['text-[12.5px]', false],
    ["style={{ fontSize: '14px' }}", false],
  ])('témoin : « %s » est trop petit → %s', (ligne, attendu) => {
    expect(tailleTropPetite(ligne)).toBe(attendu);
  });

  it('aucun texte sous 12 px (classes arbitraires px/rem/em, `fontSize` en ligne) hors exception', () => {
    const fautifs: string[] = [];
    for (const chemin of fichiers(SRC)) {
      const rel = path.relative(SRC, chemin).split(path.sep).join('/');
      if (EXCEPTIONS.has(rel)) continue;
      readFileSync(chemin, 'utf8')
        .split('\n')
        .forEach((ligne, i) => {
          if (tailleTropPetite(ligne)) fautifs.push(`${rel}:${i + 1}`);
        });
    }
    expect(fautifs, 'Texte trop petit : utiliser `text-xs` (12 px) au minimum.').toEqual([]);
  });

  it('les titres de section du menu sont à 13 px', () => {
    const barre = readFileSync(path.join(SRC, 'components/layout/Sidebar.tsx'), 'utf8');
    expect(barre).toContain('text-[13px] font-semibold uppercase');
  });
});
