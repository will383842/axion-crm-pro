/**
 * GARDE — G42-011 : la carte de couverture ne bavarde plus en production, ne
 * rend plus un composant par mouvement de souris, et ne balaie plus sa source
 * une fois par departement.
 *
 * ── Le defaut, en trois volets (mesure du 2026-08-22) ─────────────────────
 * (1) JOURNALISATION EN PRODUCTION. `const LOG = (...args) => console.log(...)`
 *     n'avait AUCUNE garde d'environnement, et 26 appels `LOG(` en dependaient.
 *     A chaque ouverture de `/coverage`, la console de l'utilisateur recevait
 *     les dimensions du canvas, les bornes du viewport, un
 *     `innerHTML.slice(0, 200)` du conteneur et les proprietes du premier
 *     departement — du diagnostic de Sprint 18.9c reste en place.
 *
 * (2) UN RENDU REACT PAR MOUVEMENT DE SOURIS. Dans `map.on('mousemove', …)`,
 *     `setHover({ … })` etait appele a CHAQUE evenement. Le garde
 *     `f.id !== hoveredId` juste au-dessus ne protege que le `setFeatureState`
 *     de MapLibre, qui peint hors React — le `setState`, lui, passait toujours.
 *     Un deplacement lent sur un seul departement declenchait donc des dizaines
 *     de rendus par seconde, chacun re-parcourant `cellsRef.current`.
 *
 * (3) UN BALAYAGE DE SOURCE PAR CELLULE. `for (const cell of cells) {
 *     map.querySourceFeatures('departements', { filter: … }) }` : jusqu'a 101
 *     balayages complets de la source (davantage si la maille descend sous le
 *     departement), chacun pour retrouver au plus UNE entite.
 *
 * ── Ce que cette garde tient ──────────────────────────────────────────────
 * Elle LIT la source de `FranceCoverageMap.tsx`. C'est une garde de source, pas
 * de comportement : MapLibre exige un contexte WebGL que jsdom n'a pas, aucun
 * test unitaire de ce depot ne peut monter cette carte pour de vrai. Meme parti
 * pris que `carte-geojson.test.ts` (G42-003), qui garde le meme fichier.
 *
 * ⚠️ CE QU'ELLE NE COUVRE PAS :
 *  · elle ne mesure aucun temps de rendu ni aucun nombre d'appels reels — elle
 *    verifie la FORME du code, pas son cout observe ;
 *  · elle ne voit pas un `console.log` ajoute dans un AUTRE fichier de l'ecran
 *    de couverture ;
 *  · le volet (3) reste partiel dans le code lui-meme : `querySourceFeatures`
 *    ne rend que les entites du viewport, donc les departements hors ecran ne
 *    sont toujours pas colores. La garde compte les balayages, elle ne promet
 *    pas que toute la France est peinte.
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
 * ⚠️ Recopie volontaire (cf. la meme note dans `carte-geojson.test.ts`) : un
 * fichier de test qui en importe un autre REJOUE toute sa suite.
 */
function sansCommentaires(texte: string): string {
  return texte.replace(/\/\*[\s\S]*?\*\//g, '').replace(/(^|[^:])\/\/[^\n]*/g, '$1');
}

/** Vrai si la declaration de `LOG` est conditionnee a l'environnement. */
export function journalisationGardee(source: string): boolean {
  const net = sansCommentaires(source);
  const declaration = /const\s+LOG[^=]*=\s*([\s\S]{0,200}?);/.exec(net);
  if (!declaration) return false;
  return /import\.meta\.env\.(DEV|PROD)/.test(declaration[1] ?? '');
}

/** Compte les `console.log` ecrits en dur, hors declaration de `LOG`. */
export function compteConsoleLogNus(source: string): number {
  const net = sansCommentaires(source);
  // La declaration de `LOG` a le droit d'en contenir un : c'est elle qui porte
  // la garde d'environnement. Tout autre `console.log` la contourne.
  const sansDeclaration = net.replace(/const\s+LOG[^=]*=[\s\S]{0,200}?;/, '');
  return (sansDeclaration.match(/console\.log\s*\(/g) ?? []).length;
}

/**
 * Compte les balayages de source faits AVEC un filtre — la forme « une passe
 * par cellule ». La passe unique, elle, appelle `querySourceFeatures` sans
 * second argument.
 */
export function compteBalayagesFiltres(source: string): number {
  const net = sansCommentaires(source);
  return (net.match(/querySourceFeatures\s*\([^)]*\{\s*filter\s*:/g) ?? []).length;
}

/**
 * Extrait le corps du gestionnaire `mousemove`, du `map.on('mousemove'` au
 * premier `setHover(` qui le suit. Chaine vide si l'un des deux manque.
 */
export function corpsAvantSetHover(source: string): string {
  const net = sansCommentaires(source);
  const debut = net.indexOf("map.on('mousemove'");
  if (debut === -1) return '';
  const fin = net.indexOf('setHover(', debut);
  if (fin === -1) return '';
  return net.slice(debut, fin);
}

/** Seuil de deplacement declare, ou `null` s'il n'y en a pas. */
export function seuilDeplacement(source: string): number | null {
  const net = sansCommentaires(source);
  const m = /const\s+SEUIL_DEPLACEMENT_PX\s*=\s*(\d+)/.exec(net);
  return m ? Number(m[1]) : null;
}

// ── Lecture du depot ──────────────────────────────────────────────────────

const source = existsSync(CARTE) ? readFileSync(CARTE, 'utf8') : '';

// ── Verrous anti-« vert sans mesure » ─────────────────────────────────────

describe('G42-011 — la garde a bien la source sous les yeux', () => {
  it('trouve le fichier de la carte', () => {
    expect(existsSync(CARTE)).toBe(true);
    // Un chemin faux, ou un fichier vide, compterait zero de tout et passerait
    // au vert sans avoir rien mesure.
    expect(source.length).toBeGreaterThan(2000);
  });

  it('y trouve bien les trois reperes que la garde inspecte', () => {
    // Si l'un de ces reperes disparait, c'est la carte qui a change de
    // mecanique — pas le defaut qui est repare. Les assertions ci-dessous
    // deviendraient muettes sans que rien ne rougisse.
    expect(source.includes('const LOG')).toBe(true);
    expect(source.includes("map.on('mousemove'")).toBe(true);
    expect(source.includes('querySourceFeatures')).toBe(true);
  });
});

// ── La regle ──────────────────────────────────────────────────────────────

describe('G42-011 — journalisation hors production', () => {
  it('conditionne `LOG` a `import.meta.env.DEV`', () => {
    expect(
      journalisationGardee(source),
      "G42-011 : `LOG` journalise en production (26 appels dependent d'elle). " +
        'Geste : `const LOG: (...args: unknown[]) => void = import.meta.env.DEV ' +
        "? (...args) => console.log('[FranceMap]', ...args) : () => {};`",
    ).toBe(true);
  });

  it("n'ecrit aucun `console.log` qui contourne cette garde", () => {
    expect(
      compteConsoleLogNus(source),
      'G42-011 : un `console.log` ecrit en dur echappe a la garde ' +
        "d'environnement. Geste : le passer par `LOG(...)`.",
    ).toBe(0);
  });
});

describe('G42-011 — un rendu React seulement quand quelque chose change', () => {
  it('filtre le `mousemove` avant de toucher a `setHover`', () => {
    const corps = corpsAvantSetHover(source);
    expect(
      corps.length,
      "G42-011 : gestionnaire `mousemove` ou appel `setHover` introuvable — la " +
        'garde ne mesure plus rien. Geste : verifier que la carte utilise ' +
        "toujours `map.on('mousemove', 'dept-fill', …)` et `setHover`.",
    ).toBeGreaterThan(0);
    expect(
      /\breturn;/.test(corps) && corps.includes('SEUIL_DEPLACEMENT_PX'),
      'G42-011 : `setHover` est appele sans court-circuit — un rendu React par ' +
        'evenement de souris. Geste : avant `setHover`, sortir si le code du ' +
        'departement est inchange ET que le deplacement reste sous ' +
        '`SEUIL_DEPLACEMENT_PX`.',
    ).toBe(true);
  });

  it('garde un seuil visuellement imperceptible', () => {
    const seuil = seuilDeplacement(source);
    expect(seuil, 'G42-011 : `SEUIL_DEPLACEMENT_PX` absent.').not.toBeNull();
    // Un seuil large ferait economiser plus de rendus, au prix d'une infobulle
    // qui saute derriere le curseur. On ne releve PAS ce plafond pour gagner
    // des rendus : au-dela, c'est le confort d'usage qu'on depense.
    expect(seuil!).toBeGreaterThan(0);
    expect(
      seuil!,
      'G42-011 : seuil de deplacement trop large — au-dela de 12 px ' +
        "l'infobulle saute visiblement derriere le curseur (offset de 14 px). " +
        'Geste : redescendre le seuil, pas relever ce plafond.',
    ).toBeLessThanOrEqual(12);
  });
});

describe('G42-011 — une seule passe sur la source des departements', () => {
  it('ne balaie plus la source une fois par cellule', () => {
    expect(
      compteBalayagesFiltres(source),
      'G42-011 : `querySourceFeatures` est appele avec un `filter` — la forme ' +
        "« une passe par cellule » (jusqu'a 101 balayages complets). Geste : " +
        'indexer les totaux dans un `Map<code, total>` et parcourir les ' +
        'entites UNE fois.',
    ).toBe(0);
  });
});

// ── TEMOIN ────────────────────────────────────────────────────────────────

describe('G42-011 — TEMOIN de la garde', () => {
  it('voit les trois volets tels qu ils etaient ecrits le 2026-08-22', () => {
    // Recopie fidele du code remplace (FranceCoverageMap.tsx:40, 254-273, 298-309).
    const avant = [
      "const LOG = (...args: unknown[]) => console.log('[FranceMap]', ...args);",
      "map.on('mousemove', 'dept-fill', (e) => {",
      '  const f = e.features?.[0];',
      '  if (!f) return;',
      "  const code = (f.properties?.['code'] as string | undefined) ?? '?';",
      '  setHover({ code, name: code, total: 0, x: e.point.x, y: e.point.y });',
      '});',
      'for (const cell of cells) {',
      "  map.querySourceFeatures('departements', { filter: ['==', ['get', 'code'], cell.code] })",
      '    .forEach((f) => { map.setFeatureState({ source: id }, { total: cell.total }); });',
      '}',
    ].join('\n');

    // C'EST le defaut en miniature, volet par volet.
    expect(journalisationGardee(avant)).toBe(false);
    expect(compteBalayagesFiltres(avant)).toBe(1);
    const corps = corpsAvantSetHover(avant);
    expect(corps.length).toBeGreaterThan(0);
    // Le seul `return` de l'ancien corps est le `if (!f) return;` — il ne
    // filtre pas le deplacement, et aucun seuil n'est nomme.
    expect(corps.includes('SEUIL_DEPLACEMENT_PX')).toBe(false);
    expect(seuilDeplacement(avant)).toBeNull();
  });

  it('ne prend pas un commentaire pour du code', () => {
    const commente = [
      "// const LOG = (...a) => console.log('[FranceMap]', ...a);",
      "/* map.querySourceFeatures('departements', { filter: ['==', 1, 1] }) */",
      "const LOG: (...args: unknown[]) => void = import.meta.env.DEV ? (...a) => console.log('x', ...a) : () => {};",
    ].join('\n');
    expect(compteBalayagesFiltres(commente)).toBe(0);
    expect(compteConsoleLogNus(commente)).toBe(0);
    expect(journalisationGardee(commente)).toBe(true);
  });

  it('ne passe pas au vert sur une source vide', () => {
    expect(journalisationGardee('')).toBe(false);
    expect(compteBalayagesFiltres('')).toBe(0);
    expect(corpsAvantSetHover('')).toBe('');
    // Sans le verrou de longueur de la premiere section, « zero balayage » sur
    // un fichier absent serait indiscernable d'une reparation.
    expect(source.length).toBeGreaterThan(2000);
  });
});
