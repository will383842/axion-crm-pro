/**
 * GARDE DE POIDS INITIAL — ce qui est atteignable STATIQUEMENT depuis
 * `src/main.tsx` est telecharge AVANT le premier pixel, sur les 37 routes.
 *
 * ── Le defaut (G42-003, S1) ───────────────────────────────────────────────
 * Mesure du 2026-08-20 sur ce depot, `pnpm build` (vite 6.4.2) :
 *
 *   dist/assets/index-B80X0RKe.js     1 044 528 o
 *   dist/assets/index-D-KqYb8V.css      162 858 o
 *   dist/assets/maplibre-CaQzARel.js    802 715 o   <-- celui-ci
 *   dist/assets/query-CnysTLUi.js        87 325 o
 *   dist/assets/router-DkEhZfFW.js       88 127 o
 *   dist/assets/react-l0sNRNKZ.js            44 o
 *   ------------------------------------------------
 *   TOTAL entree (hors sourcemaps)    2 185 597 o  = 2,19 Mo
 *
 * et `dist/index.html` portait la ligne :
 *   <link rel="modulepreload" crossorigin href="/assets/maplibre-CaQzARel.js">
 *
 * Le PRECHARGEMENT est le point : `manualChunks: { maplibre: ['maplibre-gl'] }`
 * (`vite.config.ts`) donne l'APPARENCE d'un decoupage, mais un morceau nomme
 * reste une dependance STATIQUE de l'entree tant qu'un `import` statique y
 * mene. Rollup le sort du fichier, Vite le remet dans le HTML en
 * `modulepreload` : 802 715 o telecharges pour afficher `/login`.
 *
 * La chaine mesuree (2026-08-20) :
 *   src/main.tsx
 *     -> src/app/routeTree.tsx          (importe les 37 ecrans en statique)
 *       -> src/features/coverage/CoveragePage.tsx
 *         -> src/features/coverage/FranceCoverageMap.tsx
 *           -> maplibre-gl  +  maplibre-gl/dist/maplibre-gl.css (65 534 o)
 *
 * Un SEUL des 37 ecrans emploie la carte.
 *
 * ── Ce que cette garde tient ──────────────────────────────────────────────
 * Elle reconstruit le graphe d'imports STATIQUES a partir de `src/main.tsx`,
 * et refuse qu'un paquet du registre `POIDS_LOURDS` y apparaisse. Elle ne
 * compte pas des octets : elle compte des ARETES. C'est ce qui la rend
 * deterministe et jouable dans `pnpm test` — sans build, sans reseau.
 *
 * Le `import()` dynamique n'est PAS une arete statique : c'est precisement la
 * reparation attendue, et c'est ainsi que la garde la reconnait.
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS, explicitement :
 *  · elle ne mesure pas le POIDS. Un paquet minuscule ajoute a l'entree passe.
 *    Le poids se mesure a la main, `pnpm build`, avant/apres — la porte
 *    `size-limit` du depot porte `continue-on-error` (cf. AGENTS.md).
 *  · elle ne suit pas les re-exports de `node_modules`. Un paquet leger qui
 *    re-exporte un paquet lourd resterait invisible.
 *  · elle ignore les imports de type (`import type`), qui s'effacent au build.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync, statSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const RACINE_FRONT = path.resolve(ICI, '..', '..');
const SRC = path.join(RACINE_FRONT, 'src');
const ENTREE = path.join(SRC, 'main.tsx');

// ── Fonctions pures, pour que le TEMOIN puisse les eprouver ───────────────

/**
 * Retire commentaires de ligne et de bloc.
 *
 * ⚠️ Sans ce nettoyage, les commentaires TRES denses de ce depot suffisent a
 * fausser la garde dans LES DEUX SENS : `main.tsx:48` cite « FranceCoverageMap »
 * et `FranceCoverageMap.tsx:19` cite « maplibre-gl » en prose. Une garde qui
 * lirait la prose verrait une arete la ou il n'y en a plus.
 */
export function sansCommentaires(source: string): string {
  return source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/**
 * Renvoie les specificateurs importes STATIQUEMENT par un module.
 *
 * Trois formes retenues, et une seule ecartee :
 *   import X from 's'   /  import { X } from 's'  /  import 's'   -> retenues
 *   export { X } from 's'  /  export * from 's'                   -> retenues
 *   import type X from 's'                                        -> ECARTEE
 *   import('s')                                                   -> ECARTEE
 *
 * `import type` s'efface au build (`verbatimModuleSyntax`) : le compter ferait
 * rougir la garde sur une arete qui n'existe pas dans le bundle. C'est
 * exactement le cas de `FranceCoverageMap.tsx` apres reparation, qui garde
 * `import type { CoverageMode }` — un type, zero octet.
 */
export function importsStatiques(source: string): string[] {
  const net = sansCommentaires(source);
  const trouves: string[] = [];

  // `import ... from 's'` et `export ... from 's'`, hors `import type`/`export type`.
  const avecFrom = /(?:^|[\s;}])(import|export)(\s+type\s+|\s+)([\s\S]*?)\sfrom\s*['"]([^'"]+)['"]/g;
  let m: RegExpExecArray | null;
  while ((m = avecFrom.exec(net)) !== null) {
    const estType = (m[2] ?? '').includes('type');
    // `import { type A, type B }` — toutes les specifications sont des types :
    // l'import s'efface lui aussi. Une seule valeur suffit a le garder.
    const clause = m[3] ?? '';
    const clauseToutType =
      clause.trim().startsWith('{') &&
      clause
        .replace(/[{}]/g, '')
        .split(',')
        .filter((s) => s.trim() !== '')
        .every((s) => /^\s*type\s/.test(s));
    if (estType || clauseToutType) continue;
    const spec = m[4];
    if (spec !== undefined) trouves.push(spec);
  }

  // `import 's'` a effet de bord (les feuilles de style du depot).
  // ⚠️ `[^(]` interdit `import('s')` : le dynamique n'est PAS une arete
  // statique, et c'est toute la difference que cette garde mesure.
  const effetDeBord = /(?:^|[\s;}])import\s*['"]([^'"]+)['"]/g;
  while ((m = effetDeBord.exec(net)) !== null) {
    const spec = m[1];
    if (spec !== undefined) trouves.push(spec);
  }

  return trouves;
}

/** `maplibre-gl/dist/maplibre-gl.css` -> `maplibre-gl` ; `@scope/p/x` -> `@scope/p`. */
export function nomDePaquet(specificateur: string): string {
  const morceaux = specificateur.split('/');
  if (specificateur.startsWith('@')) return morceaux.slice(0, 2).join('/');
  return morceaux[0] ?? specificateur;
}

/** Resout un specificateur LOCAL en chemin de fichier, ou `null` si c'est un paquet. */
export function resoudreLocal(specificateur: string, fichierSource: string): string | null {
  let base: string;
  if (specificateur.startsWith('@/')) {
    base = path.join(SRC, specificateur.slice(2));
  } else if (specificateur.startsWith('.')) {
    base = path.resolve(path.dirname(fichierSource), specificateur);
  } else {
    return null; // paquet de node_modules
  }

  const candidats = [
    base,
    `${base}.ts`,
    `${base}.tsx`,
    `${base}.js`,
    path.join(base, 'index.ts'),
    path.join(base, 'index.tsx'),
  ];
  for (const c of candidats) {
    if (existsSync(c) && statSync(c).isFile()) return c;
  }
  return null;
}

export interface Graphe {
  /** Modules locaux atteints, en chemin relatif POSIX depuis `frontend/`. */
  modules: Set<string>;
  /** Paquets de `node_modules` atteints, nom de paquet nu. */
  paquets: Set<string>;
}

/** Parcours en profondeur des seules aretes STATIQUES. */
export function grapheDepuis(entree: string): Graphe {
  const modules = new Set<string>();
  const paquets = new Set<string>();
  const aVoir = [entree];

  while (aVoir.length > 0) {
    const fichier = aVoir.pop() as string;
    const relatif = path.relative(RACINE_FRONT, fichier).split(path.sep).join('/');
    if (modules.has(relatif)) continue;
    modules.add(relatif);

    // Une feuille de style ne porte pas d'`import` JavaScript : on l'a comptee
    // comme module atteint, on ne la lit pas.
    if (fichier.endsWith('.css')) continue;

    const source = readFileSync(fichier, 'utf8');
    for (const spec of importsStatiques(source)) {
      const local = resoudreLocal(spec, fichier);
      if (local === null) {
        paquets.add(nomDePaquet(spec));
      } else {
        aVoir.push(local);
      }
    }
  }

  return { modules, paquets };
}

// ── Le registre ───────────────────────────────────────────────────────────

/**
 * Les paquets qui n'ont RIEN a faire dans le chargement initial.
 *
 * Le poids est celui du morceau REELLEMENT produit par `pnpm build` du
 * 2026-08-20, pas une estimation. Ajouter une entree ici, c'est promettre que
 * le paquet est charge a la demande — la garde le verifie.
 */
const POIDS_LOURDS: ReadonlyArray<{ paquet: string; octets: number; motif: string }> = [
  {
    paquet: 'maplibre-gl',
    octets: 802715,
    motif:
      'Carte MapLibre + sa feuille de style (65 534 o). Employee par 1 ecran sur 37 ' +
      '(/coverage). Doit etre atteinte par import() dynamique depuis CoveragePage.',
  },
];

// ── Lecture du depot ──────────────────────────────────────────────────────

const graphe = grapheDepuis(ENTREE);

// ── Verrous anti-« vert sans mesure » ─────────────────────────────────────
//
// Une garde qui parcourt un graphe passe au vert quand elle n'en trouve
// AUCUN : zero module atteint, donc zero paquet interdit. Ces controles
// refusent ce vert-la. Les seuils sont bas : ils detectent l'effondrement
// (entree deplacee, resolution cassee), pas la variation normale.

describe('poids initial — la garde a bien un graphe sous les yeux', () => {
  it('part bien de src/main.tsx', () => {
    expect(existsSync(ENTREE)).toBe(true);
  });

  it('atteint un vrai graphe de modules locaux', () => {
    // Au 2026-08-20 : 216 modules locaux atteints depuis main.tsx.
    expect(graphe.modules.size).toBeGreaterThanOrEqual(100);
    // Reperes nommes : si l'un disparait, c'est la resolution qui est fausse,
    // pas le depot qui a maigri.
    expect([...graphe.modules]).toContain('src/app/routeTree.tsx');
    expect([...graphe.modules]).toContain('src/features/coverage/CoveragePage.tsx');
  });

  it('sait reconnaitre les paquets de node_modules', () => {
    expect(graphe.paquets.size).toBeGreaterThanOrEqual(10);
    expect([...graphe.paquets]).toContain('react');
    expect([...graphe.paquets]).toContain('@tanstack/react-router');
  });
});

// ── La regle ──────────────────────────────────────────────────────────────

describe('poids initial — aucun poids lourd dans le chargement initial', () => {
  it.each(POIDS_LOURDS)(
    'ne charge pas $paquet ($octets o) avant le premier pixel',
    ({ paquet }) => {
      // Diff lisible : vitest montre le paquet fautif dans le tableau recu.
      const presents = [...graphe.paquets].filter((p) => p === paquet);
      expect(presents).toEqual([]);
    },
  );

  it('n atteint pas non plus le module qui porte la carte', () => {
    // Deuxieme angle sur le meme defaut : meme si quelqu'un renommait le
    // paquet ou passait par un ré-export, le MODULE de la carte ne doit pas
    // etre dans le graphe statique.
    expect([...graphe.modules]).not.toContain('src/features/coverage/FranceCoverageMap.tsx');
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────
//
// Preuve que la garde aurait trouve le defaut du 2026-08-20, et qu'elle ne
// passe pas au vert sur une entree vide. On eprouve les fonctions pures sur
// des sources fabriquees : c'est le seul moyen de montrer le ROUGE sans
// recasser le depot.

describe('poids initial — TEMOIN de la garde', () => {
  it('voit une arete statique vers maplibre-gl', () => {
    const source = [
      "import { useEffect } from 'react';",
      "import maplibregl from 'maplibre-gl';",
      "import 'maplibre-gl/dist/maplibre-gl.css';",
    ].join('\n');
    const paquets = importsStatiques(source).map(nomDePaquet);
    // C'EST le defaut G42-003 en miniature : deux aretes, un seul paquet.
    expect(paquets).toContain('maplibre-gl');
    expect(paquets.filter((p) => p === 'maplibre-gl')).toHaveLength(2);
  });

  it('ne voit PAS d arete statique quand l import est dynamique', () => {
    const source = [
      "import { lazy } from 'react';",
      "const Carte = lazy(async () => import('./FranceCoverageMap'));",
    ].join('\n');
    const specs = importsStatiques(source);
    // La reparation attendue, reconnue : `react` reste, la carte disparait.
    expect(specs).toEqual(['react']);
    expect(specs).not.toContain('./FranceCoverageMap');
  });

  it('ne prend pas un commentaire pour une arete', () => {
    const source = [
      "// Sprint 18.9c — StrictMode desactive pour MapLibre :",
      "//   import maplibregl from 'maplibre-gl';",
      "/* voir aussi import 'maplibre-gl/dist/maplibre-gl.css' */",
      "import { StrictMode } from 'react';",
    ].join('\n');
    expect(importsStatiques(source)).toEqual(['react']);
    // Sans le nettoyage, la prose du depot suffirait a faire rougir la garde
    // APRES reparation : un faux rouge est aussi couteux qu'un faux vert.
    expect(importsStatiques(source).map(nomDePaquet)).not.toContain('maplibre-gl');
  });

  it('ne compte pas un import de type, qui s efface au build', () => {
    const source = [
      "import type { Map as MlMap } from 'maplibre-gl';",
      "import { type CoverageMode, type Cell } from './FranceCoverageMap';",
      "import { useState } from 'react';",
    ].join('\n');
    expect(importsStatiques(source)).toEqual(['react']);
  });

  it('ne passe pas au vert sur une source vide', () => {
    expect(importsStatiques('')).toEqual([]);
    // Le verrou de la premiere section exige >= 100 modules : c'est lui qui
    // empeche « aucune arete trouvee » de se lire comme « aucun poids lourd ».
    expect(graphe.modules.size).toBeGreaterThanOrEqual(100);
  });

  it('ne passe pas au vert sur un registre vide', () => {
    expect(POIDS_LOURDS.length).toBeGreaterThanOrEqual(1);
    expect(POIDS_LOURDS.map((p) => p.paquet)).toContain('maplibre-gl');
  });

  it('sait resoudre l alias @/ comme le fait vite', () => {
    // Si l'alias cessait d'etre resolu, TOUT le graphe se reduirait a main.tsx
    // et la garde deviendrait un vert parfait et vide.
    const resolu = resoudreLocal('@/features/coverage/CoveragePage', ENTREE);
    expect(resolu).not.toBeNull();
    expect(resolu?.endsWith('CoveragePage.tsx')).toBe(true);
  });
});
