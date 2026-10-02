/**
 * Détecteur de TUTOIEMENT dans les textes affichés de la console.
 *
 * Utilisé par `tests/lib/vouvoiement.test.ts`. Isolé ici pour que le même code
 * serve à la garde ET à un contrôle ponctuel hors vitest.
 *
 * Ce qu'il lit — et seulement cela :
 *  - les chaînes littérales (`'…'`, `"…"`, gabarits `` `…` ``) des `.ts`/`.tsx`
 *    de `src/` ;
 *  - le texte JSX (`<p>Texte</p>`) ;
 *  - les valeurs de `src/locales/fr.json`.
 *
 * Ce qu'il ne lit PAS : les commentaires (la documentation interne peut
 * tutoyer le lecteur développeur, ce n'est pas un texte affiché), les noms
 * d'identifiants (`ton: 'ambre'` est une propriété, pas un mot), et les
 * chemins d'import.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative, sep } from 'node:path';
import ts from 'typescript';

export interface Occurrence {
  fichier: string;
  ligne: number;
  texte: string;
  motif: string;
}

/**
 * Pronoms et déterminants de la 2e personne du singulier, en mot entier, plus
 * l'élision (« t'envoie ») et l'inversion (« veux-tu », « connecte-toi »).
 * La frontière de mot est écrite à la main (`\p{L}`) : `\b` ignore les
 * lettres accentuées et verrait un mot « te » dans « Complète ».
 */
const PRONOMS =
  /(?<![\p{L}\p{N}_'’-])(?:tu|ton|ta|tes|toi|te)(?![\p{L}\p{N}_'’-])|(?<![\p{L}\p{N}_])t['’](?=\p{L})|(?<=\p{L})-(?:tu|toi)(?![\p{L}\p{N}_])/iu;

/**
 * Impératifs de la 2e personne du singulier, tels qu'on les écrit en tête de
 * phrase dans une interface (« Lance ton premier scrape », « Choisis… »).
 * Sensibles à la casse : en minuscule, plusieurs de ces formes sont aussi des
 * 3e personnes ou des noms (« la relance », « une invite »).
 */
const IMPERATIFS =
  /(?<![\p{L}\p{N}_-])(?:Lance|Choisis|Crée|Ajoute|Saisis|Vérifie|Reçois|Essaie|Tape|Invite|Configure|Renseigne|Compose|Explore|Découvre|Améliore|Reprends|Visualise|Réinitialise|Clique|Sélectionne|Indique|Attends|Enrichis|Consulte|Utilise|Remplis|Coche|Ouvre|Fais|Modifie|Supprime|Envoie|Exporte|Importe|Regarde|Connecte-toi|Assure-toi|Vérifie-le)(?![\p{L}\p{N}_-])/u;

/**
 * Les mêmes impératifs écrits en MINUSCULE, mais seulement là où une forme
 * minuscule ne peut être qu'un ordre : en tête de PHRASE (début du texte suivi
 * d'au moins un autre mot, ou après « . », « ! », « ? », « : », « — »), ou
 * après « puis », « ensuite », « alors » (« puis lance la collecte »).
 *
 * Pourquoi pas partout : en milieu de phrase, ces formes sont aussi des noms
 * ou des participes (« la relance », « contacts enrichis », « fiches
 * choisies »), et une chaîne SANS espace (« active », « copie ») est le plus
 * souvent une valeur technique (statut, clé) et non un texte lu. Avant cet
 * élargissement, « puis lance la collecte » ou « fais-le » passaient : seule
 * la majuscule de tête était vue.
 */
const IMPERATIFS_MINUSCULES =
  /(?:^\s*|[.!?:—–]\s+|(?<![\p{L}\p{N}_])(?:puis|ensuite|alors)\s+)(?:lance|choisis|crée|ajoute|saisis|vérifie|reçois|essaie|tape|invite|configure|renseigne|compose|explore|découvre|améliore|reprends|visualise|réinitialise|clique|sélectionne|indique|attends|enrichis|consulte|utilise|remplis|coche|ouvre|fais|modifie|supprime|envoie|exporte|importe|regarde)(?:-(?:le|la|les|moi|nous|en|y))?(?=\s+[\p{L}\p{N}«"'’])/u;

/**
 * « Active » et « Copie » sont d'abord des ADJECTIFS et des NOMS dans une
 * interface (« Active » sur une pastille d'état, « Copie du consentement »).
 * Ils ne comptent comme impératifs que suivis d'un complément d'objet
 * (« Active les relances », « Copie le lien », « copie-le dans… ») — et, en
 * minuscule, seulement en tête de phrase, comme les autres.
 */
const IMPERATIFS_AMBIGUS =
  /(?:(?<![\p{L}\p{N}_-])(?:Active|Copie)|(?:^\s*|[.!?:—–]\s+|(?<![\p{L}\p{N}_])(?:puis|ensuite|alors)\s+)(?:active|copie))(?:-(?:le|la|les)(?![\p{L}])|(?=\s+(?:le|la|les|ce|cet|cette|ces|ton|ta|tes|un|une)\s|\s+l['’]))/u;

/** Premier motif trouvé dans `texte`, ou `null`. */
export function motifTutoiement(texte: string): string | null {
  const pronom = PRONOMS.exec(texte);
  if (pronom !== null) return pronom[0];
  const imperatif = IMPERATIFS.exec(texte);
  if (imperatif !== null) return imperatif[0];
  const minuscule = IMPERATIFS_MINUSCULES.exec(texte);
  if (minuscule !== null) return minuscule[0].trim();
  const ambigu = IMPERATIFS_AMBIGUS.exec(texte);
  if (ambigu !== null) return ambigu[0].trim();
  return null;
}

function fichiers(dossier: string, acc: string[] = []): string[] {
  for (const entree of readdirSync(dossier)) {
    const chemin = join(dossier, entree);
    if (statSync(chemin).isDirectory()) fichiers(chemin, acc);
    else if (/\.tsx?$/.test(chemin) && !chemin.endsWith('.d.ts')) acc.push(chemin);
  }
  return acc;
}

/** Les textes potentiellement affichés d'un module, avec leur ligne. */
export function textesDuModule(chemin: string, source: string): Array<{ ligne: number; texte: string }> {
  const fichier = ts.createSourceFile(
    chemin,
    source,
    ts.ScriptTarget.Latest,
    true,
    chemin.endsWith('.tsx') ? ts.ScriptKind.TSX : ts.ScriptKind.TS,
  );
  const sortie: Array<{ ligne: number; texte: string }> = [];

  const noter = (noeud: ts.Node, texte: string) => {
    if (texte.trim() === '') return;
    const { line } = fichier.getLineAndCharacterOfPosition(noeud.getStart(fichier));
    sortie.push({ ligne: line + 1, texte });
  };

  const visiter = (noeud: ts.Node): void => {
    // Les chemins de module ne sont pas des textes.
    if (ts.isImportDeclaration(noeud) || ts.isExportDeclaration(noeud)) return;
    if (ts.isStringLiteral(noeud) || ts.isNoSubstitutionTemplateLiteral(noeud)) {
      noter(noeud, noeud.text);
    } else if (ts.isTemplateExpression(noeud)) {
      noter(noeud, [noeud.head.text, ...noeud.templateSpans.map((s) => s.literal.text)].join(' … '));
    } else if (ts.isJsxText(noeud)) {
      noter(noeud, noeud.text);
    }
    ts.forEachChild(noeud, visiter);
  };
  visiter(fichier);
  return sortie;
}

function valeursJson(valeur: unknown, acc: string[] = []): string[] {
  if (typeof valeur === 'string') acc.push(valeur);
  else if (valeur !== null && typeof valeur === 'object') {
    for (const v of Object.values(valeur)) valeursJson(v, acc);
  }
  return acc;
}

/** Toutes les occurrences de tutoiement sous `racineSrc`. */
export function chercherTutoiement(racineSrc: string): Occurrence[] {
  const trouve: Occurrence[] = [];
  const rel = (c: string) => relative(racineSrc, c).split(sep).join('/');

  for (const chemin of fichiers(racineSrc)) {
    const source = readFileSync(chemin, 'utf8');
    for (const { ligne, texte } of textesDuModule(chemin, source)) {
      const motif = motifTutoiement(texte);
      if (motif !== null) trouve.push({ fichier: rel(chemin), ligne, texte: texte.trim(), motif });
    }
  }

  const fr = join(racineSrc, 'locales', 'fr.json');
  for (const texte of valeursJson(JSON.parse(readFileSync(fr, 'utf8')) as unknown)) {
    const motif = motifTutoiement(texte);
    if (motif !== null) trouve.push({ fichier: 'locales/fr.json', ligne: 0, texte, motif });
  }
  return trouve;
}
