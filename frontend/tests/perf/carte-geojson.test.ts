/**
 * GARDE — le GeoJSON des departements n'est telecharge QU'UNE FOIS.
 *
 * ── Le defaut (deuxieme moitie de G42-003, S1) ────────────────────────────
 * `FranceCoverageMap.tsx` telechargeait le meme fichier DEUX FOIS a chaque
 * montage de `/coverage` :
 *
 *   1. `fetch(geojsonUrl, { credentials: 'same-origin' })` — un appel dont le
 *      corps entier (`await r.text()`, puis `JSON.parse(text)`) ne servait
 *      qu'a ecrire quatre lignes dans la console :
 *          LOG('fetch geojson — status=', …)
 *          LOG('fetch geojson — body length=', …)
 *   2. `map.addSource('departements', { type: 'geojson', data: geojsonUrl })`
 *      — celui-la, le vrai, celui dont la carte a besoin.
 *
 * Poids du fichier, releve par l'audit G42-003 : 1 079 714 o. Donc
 * 2 159 428 o telecharges, parses et jetes a moitie, a chaque ouverture de
 * l'ecran. Le premier appel n'affiche RIEN a l'utilisateur : il est
 * integralement du diagnostic de Sprint 18.9c reste en place.
 *
 * ⚠️ Il n'etait pas seulement couteux, il etait NUISIBLE : ce `fetch` est
 * precisement celui que `src/main.tsx:47` accuse d'« AbortError sur fetch
 * geojson » au double-montage, et pour lequel le depot a DESACTIVE
 * `StrictMode` sur toute l'application.
 *
 * ── Ce que cette garde tient ──────────────────────────────────────────────
 * Elle lit la source de `FranceCoverageMap.tsx` et compte les endroits qui
 * DEMANDENT le GeoJSON. Il doit y en avoir exactement un, et ce doit etre
 * `addSource`. Une garde de source, pas de comportement : maplibre exige un
 * contexte WebGL que jsdom n'a pas, aucun test unitaire ne peut monter cette
 * carte pour de vrai. La garde le dit plutot que de faire semblant.
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS :
 *  · elle ne voit pas un second telechargement fait depuis un AUTRE fichier ;
 *  · elle ne mesure pas le poids reel du GeoJSON — il n'est pas commite
 *    (cf. le commentaire de `FranceCoverageMap.tsx`, telecharge par script
 *    d'installation). Le chiffre de 1 079 714 o vient de l'audit, pas d'ici.
 */
import { describe, it, expect } from 'vitest';
import { readFileSync, existsSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ICI = path.dirname(fileURLToPath(import.meta.url));
const CARTE = path.resolve(ICI, '..', '..', 'src', 'features', 'coverage', 'FranceCoverageMap.tsx');

// ── Fonctions pures, pour que le TEMOIN puisse les eprouver ───────────────

/**
 * Retire commentaires de ligne et de bloc.
 *
 * ⚠️ Recopie volontaire de `graphe-initial.test.ts` plutot qu'un import : un
 * fichier de test qui en importe un autre REJOUE toute sa suite (mesure :
 * 12 cas devenaient 18, et le parcours du graphe d'imports tournait deux
 * fois). Six lignes dupliquees valent mieux qu'une suite fantome.
 */
function sansCommentaires(texte: string): string {
  return texte.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/**
 * Compte les appels `fetch(` portant sur le GeoJSON.
 *
 * On cherche `fetch(` suivi de la variable d'URL ou du chemin en dur. Un
 * `fetch` vers une AUTRE ressource depuis ce module resterait invisible : ce
 * n'est pas ce que la garde surveille.
 */
export function comptePreleveementsGeojson(source: string): number {
  const net = sansCommentaires(source);
  const motifs = [/\bfetch\s*\(\s*geojsonUrl\b/g, /\bfetch\s*\(\s*['"`][^'"`]*departements[^'"`]*['"`]/g];
  let total = 0;
  for (const motif of motifs) total += (net.match(motif) ?? []).length;
  return total;
}

/** Compte les `addSource` de type geojson — la demande LEGITIME. */
export function compteSourcesGeojson(source: string): number {
  const net = sansCommentaires(source);
  return (net.match(/addSource\s*\(/g) ?? []).length;
}

// ── Lecture du depot ──────────────────────────────────────────────────────

const source = existsSync(CARTE) ? readFileSync(CARTE, 'utf8') : '';

// ── Verrous anti-« vert sans mesure » ─────────────────────────────────────

describe('GeoJSON departements — la garde a bien la source sous les yeux', () => {
  it('trouve le fichier de la carte', () => {
    expect(existsSync(CARTE)).toBe(true);
    // Un fichier vide, ou un chemin faux, compterait zero prelevement et
    // passerait au vert sans rien avoir mesure.
    expect(source.length).toBeGreaterThan(2000);
  });

  it('y trouve bien la source geojson attendue', () => {
    // Repere nomme : si celui-ci disparait, c'est la carte qui a change de
    // mecanique, pas le defaut qui est repare.
    expect(source).toContain('departements');
    expect(compteSourcesGeojson(source)).toBe(1);
  });
});

// ── La regle ──────────────────────────────────────────────────────────────

describe('GeoJSON departements — un seul telechargement par montage', () => {
  it('ne double PAS la source par un fetch de diagnostic', () => {
    // 1 079 714 o (chiffre de l'audit G42-003) : un doublon coute autant que
    // l'original.
    expect(comptePreleveementsGeojson(source)).toBe(0);
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────

describe('GeoJSON departements — TEMOIN de la garde', () => {
  it('voit le doublon tel qu il etait ecrit le 2026-08-20', () => {
    // Recopie fidele du code retire (`FranceCoverageMap.tsx:166-182`).
    const avant = [
      "      const geojsonUrl = import.meta.env['VITE_DEPARTEMENTS_GEOJSON_URL'] ?? '/tiles/admin/departements.geojson';",
      "      fetch(geojsonUrl, { credentials: 'same-origin' })",
      "        .then(async (r) => { const text = await r.text(); LOG('body length=', text.length); })",
      "        .catch((err) => LOG('network error', err));",
      "      map.addSource('departements', { type: 'geojson', data: geojsonUrl, generateId: true });",
    ].join('\n');
    // C'EST le defaut en miniature : deux demandes, un seul affichage.
    expect(comptePreleveementsGeojson(avant)).toBe(1);
    expect(compteSourcesGeojson(avant)).toBe(1);
  });

  it('ne prend pas un commentaire pour un telechargement', () => {
    const commente = [
      '// Retire le 2026-08-20 : fetch(geojsonUrl) doublait la source.',
      "/* fetch('/tiles/admin/departements.geojson') — diagnostic Sprint 18.9c */",
      "map.addSource('departements', { type: 'geojson', data: geojsonUrl });",
    ].join('\n');
    expect(comptePreleveementsGeojson(commente)).toBe(0);
  });

  it('ne passe pas au vert sur une source vide', () => {
    expect(comptePreleveementsGeojson('')).toBe(0);
    expect(compteSourcesGeojson('')).toBe(0);
    // Sans le verrou de longueur de la premiere section, « zero prelevement »
    // sur un fichier absent serait indiscernable d'une reparation.
    expect(source.length).toBeGreaterThan(2000);
  });
});
